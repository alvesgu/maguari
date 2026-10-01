<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Http;

use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Everything under /api/client/ answers in JSON, whatever the Accept header
 * says. Everything else keeps the HTML error pages.
 */
final class ClientApiErrorsTest extends TestCase
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

    private function request(string $method, string $path, string $accept = 'text/html'): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path)->withHeader('Accept', $accept);

        return $this->environment->app()->handle($request);
    }

    private function assertJsonError(ResponseInterface $response, int $status, string $code): void
    {
        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame(json_encode(['error' => $code]), (string) $response->getBody());
        $this->assertStringContainsString("frame-ancestors 'none'", $response->getHeaderLine('Content-Security-Policy'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function acceptHeaders(): array
    {
        return [
            'browser' => ['text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'],
            'json' => ['application/json'],
            'anything' => ['*/*'],
            'xml' => ['application/xml'],
        ];
    }

    /**
     * @dataProvider acceptHeaders
     */
    public function testUnknownRouteIsJsonWhateverTheAcceptHeader(string $accept): void
    {
        $this->assertJsonError($this->request('POST', '/api/client/nonexistent', $accept), 404, 'not_found');
    }

    public function testTheGroupRootIsJson(): void
    {
        $this->assertJsonError($this->request('GET', '/api/client'), 404, 'not_found');
        $this->assertJsonError($this->request('GET', '/api/client/'), 404, 'not_found');
    }

    public function testWrongMethod(): void
    {
        $response = $this->request('GET', '/api/client/enroll');

        $this->assertJsonError($response, 405, 'method_not_allowed');
        $this->assertSame('POST', $response->getHeaderLine('Allow'));
    }

    public function testSignedRoutesStillFailClosed(): void
    {
        $this->assertJsonError($this->request('POST', '/api/client/heartbeat'), 401, 'unauthorized');
    }

    /**
     * Outside /api/client, the error format is negotiated by Accept as before:
     * Maguari's HTML page for browsers.
     */
    public function testOtherPathsKeepTheHtmlPages(): void
    {
        foreach (['/api/clientele', '/api', '/nonexistent'] as $path) {
            $response = $this->request('GET', $path);

            $this->assertSame(404, $response->getStatusCode());
            $this->assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'), $path);
            $this->assertStringContainsString('<a href="/admin">Go to the dashboard</a>', (string) $response->getBody());
        }
    }
}
