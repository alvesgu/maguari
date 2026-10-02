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
        return App::create(null, null, null, null, MAGUARI_TEST_SESSION_PATH, logErrors: $logErrors);
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
        $environment = $this->environment;
        $app = App::create($environment->access, $environment->fleet, $environment->clients, $environment->monitoring, MAGUARI_TEST_SESSION_PATH, $environment->clock, $logErrors);
        $app->get('/test-failure', function (): never {
            throw new RuntimeException('secret-detail');
        });
        $app->post('/api/client/test-failure', function (): never {
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
            'projects' => ['GET', '/admin/projects'],
            'add project' => ['POST', '/admin/projects'],
            'project instances' => ['GET', '/admin/projects/1'],
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

    public function testFromEnvironmentIsNotReadyWithoutASecretKey(): void
    {
        putenv('MAGUARI_DATABASE=' . $this->environment->database->path());
        putenv('MAGUARI_SECRET_KEY_FILE=' . $this->environment->directory . '/missing.key');
        $previousLog = ini_set('error_log', $this->environment->directory . '/error.log');

        try {
            $app = App::fromEnvironment();
            $admin = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/admin'));
            $client = $app->handle((new ServerRequestFactory())->createServerRequest('POST', '/api/client/enroll'));
        } finally {
            putenv('MAGUARI_DATABASE');
            putenv('MAGUARI_SECRET_KEY_FILE');
            ini_set('error_log', $previousLog === false ? '' : $previousLog);
        }

        $this->assertSame(503, $admin->getStatusCode());
        $this->assertStringContainsString('create-secret-key', (string) $admin->getBody());
        $this->assertStringNotContainsString('missing.key', (string) $admin->getBody());
        $this->assertSame(503, $client->getStatusCode());
        $this->assertSame('{"error":"unavailable"}', (string) $client->getBody());
        $this->assertStringContainsString('missing.key', (string) file_get_contents($this->environment->directory . '/error.log'));
    }

    /**
     * @return array<string, array{array<string, string>, string}> environment and a text the log must contain
     */
    public static function startupFailures(): array
    {
        return [
            'not a database' => [['MAGUARI_DATABASE' => '{dir}/garbage.sqlite'], 'file is not a database'],
            'unknown credentials setting' => [['MAGUARI_GCP_CREDENTIALS' => 'key-file'], 'MAGUARI_GCP_CREDENTIALS'],
        ];
    }

    /**
     * @dataProvider startupFailures
     * @param array<string, string> $variables
     */
    public function testStartupFailureAnswers503AndLogsTheDetails(array $variables, string $logged): void
    {
        file_put_contents($this->environment->directory . '/garbage.sqlite', str_repeat('garbage ', 200));
        $variables += ['MAGUARI_DATABASE' => $this->environment->database->path(), 'MAGUARI_SECRET_KEY_FILE' => $this->environment->secretKeyFile->path()];
        $logFile = $this->environment->directory . '/error.log';
        $previousLog = ini_set('error_log', $logFile);

        foreach ($variables as $name => $value) {
            putenv($name . '=' . str_replace('{dir}', $this->environment->directory, $value));
        }

        try {
            $app = App::fromEnvironment();
            $admin = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/admin'));
            $client = $app->handle((new ServerRequestFactory())->createServerRequest('POST', '/api/client/enroll'));
        } finally {
            foreach (array_keys($variables) as $name) {
                putenv($name);
            }

            ini_set('error_log', $previousLog === false ? '' : $previousLog);
        }

        $this->assertSame(503, $admin->getStatusCode());
        $this->assertSame(App::STARTUP_FAILED_MESSAGE, (string) $admin->getBody());
        $this->assertSecurityHeaders($admin);
        $this->assertSame(503, $client->getStatusCode());
        $this->assertSame('{"error":"unavailable"}', (string) $client->getBody());
        $this->assertStringContainsString($logged, (string) file_get_contents($logFile));
    }

    /**
     * The real entry point, in its own PHP process.
     */
    public function testEntryPointNeverShowsAStackTrace(): void
    {
        $garbage = $this->environment->directory . '/garbage.sqlite';
        file_put_contents($garbage, str_repeat('garbage ', 200));
        $logFile = $this->environment->directory . '/error.log';
        $environment = getenv() + [];
        $environment['MAGUARI_DATABASE'] = $garbage;
        $environment['MAGUARI_SECRET_KEY_FILE'] = $this->environment->secretKeyFile->path();
        $environment['REQUEST_METHOD'] = 'GET';
        $environment['REQUEST_URI'] = '/admin';
        $command = [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_log=' . $logFile, '-d', 'variables_order=EGPCS', dirname(__DIR__, 2) . '/public/index.php'];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        proc_close($process);

        $this->assertSame(App::STARTUP_FAILED_MESSAGE, $stdout);
        $this->assertSame('', $stderr);
        $this->assertStringNotContainsString($garbage, $stdout);
        $this->assertStringContainsString('file is not a database', (string) file_get_contents($logFile));
    }

    public function testFromEnvironmentIsReadyWithDatabaseAndSecretKey(): void
    {
        putenv('MAGUARI_DATABASE=' . $this->environment->database->path());
        putenv('MAGUARI_SECRET_KEY_FILE=' . $this->environment->secretKeyFile->path());

        try {
            $response = App::fromEnvironment()->handle((new ServerRequestFactory())->createServerRequest('POST', '/api/client/enroll'));
        } finally {
            putenv('MAGUARI_DATABASE');
            putenv('MAGUARI_SECRET_KEY_FILE');
        }

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('{"error":"bad_request"}', (string) $response->getBody());
    }

    public function testClientApiIsUnavailableWhileTheDatabaseIsNotReady(): void
    {
        foreach (['/api/client/enroll', '/api/client/heartbeat', '/api/client/anything'] as $path) {
            $response = $this->request('POST', $path, $this->notReadyApp());

            $this->assertSame(503, $response->getStatusCode());
            $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
            $this->assertSame('{"error":"unavailable"}', (string) $response->getBody());
            $this->assertSecurityHeaders($response);
        }
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

    /**
     * @return array<string, array{string, string, int, string}>
     */
    public static function errorPages(): array
    {
        return [
            'not found' => ['GET', '/nonexistent', 404, '404 Not Found'],
            'method not allowed' => ['DELETE', '/test-failure', 405, '405 Method Not Allowed'],
            'server error' => ['GET', '/test-failure', 500, '500 Internal Server Error'],
        ];
    }

    /**
     * Slim's default HTML error page links back with an inline onclick, which
     * the Content-Security-Policy blocks, so its link did nothing.
     *
     * @dataProvider errorPages
     */
    public function testErrorPagesAreMaguarisOwnWithoutInlineScripts(string $method, string $path, int $status, string $title): void
    {
        $response = $this->request($method, $path, $this->appWithFailingRoute(false));
        $body = (string) $response->getBody();

        $this->assertSame($status, $response->getStatusCode());
        $this->assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('<h1>' . $title . '</h1>', $body);
        $this->assertStringContainsString('<a href="/admin">Go to the dashboard</a>', $body);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $body, 'No inline event handlers.');
        $this->assertDoesNotMatchRegularExpression('/<script|<style|\sstyle\s*=|javascript:/i', $body, 'No inline scripts or styles.');
        $this->assertStringNotContainsString('href="#"', $body);
        $this->assertStringNotContainsString('secret-detail', $body);
        $this->assertSecurityHeaders($response);
    }

    public function testErrorPageForABrowsersAcceptHeader(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/nonexistent')
            ->withHeader('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8');

        $body = (string) $this->environment->app()->handle($request)->getBody();

        $this->assertStringContainsString('<a href="/admin">Go to the dashboard</a>', $body);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $body);
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
            $response = $this->request('DELETE', '/test-failure', $this->appWithFailingRoute(true));
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

    public function testClientApiServerErrorIsJsonAndLogged(): void
    {
        $response = null;
        $logged = $this->captureErrorLog(function () use (&$response): void {
            $response = $this->request('POST', '/api/client/test-failure', $this->appWithFailingRoute(true));
        });

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('{"error":"server_error"}', (string) $response->getBody());
        $this->assertStringContainsString('secret-detail', $logged);
    }
}
