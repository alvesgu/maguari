<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Http;

use Maguari\Server\Http\App;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Slim\App as SlimApp;
use Slim\Psr7\Factory\ServerRequestFactory;

final class AppTest extends TestCase
{
    private function request(string $method, string $path, ?SlimApp $app = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);

        return ($app ?? App::create(logErrors: false))->handle($request);
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
        $app = App::create(logErrors: $logErrors);
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
    }

    public function testAdminIsFailClosed(): void
    {
        $response = $this->request('GET', '/admin');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Not available yet', (string) $response->getBody());
        $this->assertSecurityHeaders($response);
    }

    public function testClientHeartbeatIsFailClosed(): void
    {
        $response = $this->request('POST', '/api/client/heartbeat');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Not available yet', (string) $response->getBody());
        $this->assertSecurityHeaders($response);
    }

    public function testAuthLoginIsNotImplemented(): void
    {
        $response = $this->request('GET', '/auth/login');

        $this->assertSame(501, $response->getStatusCode());
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
        $logged = $this->captureErrorLog(fn () => $this->request('GET', '/nonexistent', App::create()));

        $this->assertSame('', $logged);
    }

    public function testMethodNotAllowedIsNotLogged(): void
    {
        $response = null;
        $logged = $this->captureErrorLog(function () use (&$response): void {
            $response = $this->request('DELETE', '/auth/login', App::create());
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
