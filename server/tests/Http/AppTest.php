<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Http;

use Maguari\Server\Http\App;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Slim\App as SlimApp;
use Slim\Psr7\Factory\ServerRequestFactory;

final class AppTest extends TestCase
{
    private TestEnvironment $environment;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
    }

    protected function tearDown(): void
    {
        $this->environment->cleanUp();
    }

    private function request(string $method, string $path, ?SlimApp $app = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);

        return ($app ?? $this->environment->app())->handle($request);
    }

    /**
     * The app as it runs while the database is missing or not migrated.
     */
    private function notReadyApp(bool $logErrors = false): SlimApp
    {
        return App::create(null, MAGUARI_TEST_SESSION_PATH, logErrors: $logErrors);
    }

    /**
     * Runs $callback with PHP's error log redirected to a temporary file and
     * returns what was logged.
     */
    private function captureErrorLog(callable $callback): string
    {
        $logFile = tempnam(sys_get_temp_dir(), 'maguari-error-log-');
        $previous = ini_set('error_log', $logFile);

        try {
            $callback();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $logged = (string) file_get_contents($logFile);
        unlink($logFile);

        return $logged;
    }

    private function appWithFailingRoute(bool $logErrors): SlimApp
    {
        $app = $this->notReadyApp($logErrors);
        $app->get('/test-failure', function (): never {
            throw new RuntimeException('secret-detail');
        });

        return $app;
    }

    private function assertSecurityHeaders(ResponseInterface $response): void
    {
        $this->assertStringContainsString("frame-ancestors 'none'", $response->getHeaderLine('Content-Security-Policy'));
        $this->assertStringContainsString('max-age=', $response->getHeaderLine('Strict-Transport-Security'));
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $this->assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
    }

    public function testAdminRedirectsToLoginWithoutASession(): void
    {
        $response = $this->request('GET', '/admin');

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/auth/login', $response->getHeaderLine('Location'));
        $this->assertSecurityHeaders($response);
    }

    public function testLoginSaysSetupIsNotCompleteWithoutAnAdministrator(): void
    {
        $response = $this->request('GET', '/auth/login');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Setup not complete', (string) $response->getBody());
        $this->assertSecurityHeaders($response);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function notReadyRoutes(): array
    {
        return [
            'admin' => ['GET', '/admin'],
            'logout' => ['POST', '/admin/logout'],
            'setup' => ['GET', '/auth/setup?token=x'],
            'login' => ['POST', '/auth/login'],
        ];
    }

    /**
     * @dataProvider notReadyRoutes
     */
    public function testAdminAndAuthAreUnavailableWhileTheDatabaseIsNotReady(string $method, string $path): void
    {
        $response = $this->request($method, $path, $this->notReadyApp());

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringContainsString('issue-setup-token', (string) $response->getBody());
        $this->assertSecurityHeaders($response);
    }

    public function testFromEnvironmentIsNotReadyWithoutADatabase(): void
    {
        putenv('MAGUARI_DATABASE=' . $this->environment->directory . '/missing/maguari.sqlite');

        try {
            $response = App::fromEnvironment()->handle((new ServerRequestFactory())->createServerRequest('GET', '/admin'));
        } finally {
            putenv('MAGUARI_DATABASE');
        }

        $this->assertSame(503, $response->getStatusCode());
        $this->assertFileDoesNotExist($this->environment->directory . '/missing/maguari.sqlite');
    }

    public function testClientHeartbeatIsFailClosed(): void
    {
        $response = $this->request('POST', '/api/client/heartbeat');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Not available yet', (string) $response->getBody());
        $this->assertSecurityHeaders($response);
    }

    public function testUnknownRouteIsNotFound(): void
    {
        $response = $this->request('GET', '/nonexistent');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSecurityHeaders($response);
    }

    public function testErrorDetailsAreNotShown(): void
    {
        $response = $this->request('GET', '/test-failure', $this->appWithFailingRoute(false));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('secret-detail', (string) $response->getBody());
        $this->assertSecurityHeaders($response);
    }

    public function testNotFoundIsNotLogged(): void
    {
        $logged = $this->captureErrorLog(fn () => $this->request('GET', '/nonexistent', $this->notReadyApp(true)));

        $this->assertSame('', $logged);
    }

    public function testMethodNotAllowedIsNotLogged(): void
    {
        $response = null;
        $logged = $this->captureErrorLog(function () use (&$response): void {
            $response = $this->request('DELETE', '/api/client/heartbeat', $this->notReadyApp(true));
        });

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSecurityHeaders($response);
        $this->assertSame('', $logged);
    }

    public function testServerErrorIsLoggedWhenEnabled(): void
    {
        $logged = $this->captureErrorLog(fn () => $this->request('GET', '/test-failure', $this->appWithFailingRoute(true)));

        $this->assertStringContainsString('secret-detail', $logged);
    }
}
