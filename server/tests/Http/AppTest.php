<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Http;

use Maguari\Server\Http\App;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

final class AppTest extends TestCase
{
    private function request(string $method, string $path): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);

        return App::create()->handle($request);
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
}
