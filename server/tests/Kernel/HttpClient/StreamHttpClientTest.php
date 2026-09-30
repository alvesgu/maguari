<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Kernel\HttpClient;

use Maguari\Server\Kernel\HttpClient\HttpClientFailure;
use Maguari\Server\Kernel\HttpClient\HttpRequest;
use Maguari\Server\Kernel\HttpClient\StreamHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Runs against PHP's built-in server on 127.0.0.1, never the internet.
 */
final class StreamHttpClientTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static string $baseUrl;

    public static function setUpBeforeClass(): void
    {
        $port = self::freePort();
        self::$baseUrl = 'http://127.0.0.1:' . $port;
        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fixtures/router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );

        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage, 0.1);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(50_000);
        }

        self::fail('The built-in server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }

    public function testSendsMethodHeadersAndBody(): void
    {
        $response = (new StreamHttpClient())->send(HttpRequest::postForm(
            self::$baseUrl . '/echo',
            ['grant_type' => 'refresh_token', 'refresh_token' => 'a b&c'],
            ['Authorization' => 'Bearer abc'],
        ));

        $this->assertSame(200, $response->status);
        $this->assertSame('yes', $response->header('x-fixture'));
        $this->assertSame([
            'method' => 'POST',
            'authorization' => 'Bearer abc',
            'contentType' => 'application/x-www-form-urlencoded',
            'body' => 'grant_type=refresh_token&refresh_token=a%20b%26c',
        ], $response->json());
    }

    public function testReturnsErrorResponsesWithTheirBody(): void
    {
        $response = (new StreamHttpClient())->send(HttpRequest::get(self::$baseUrl . '/forbidden'));

        $this->assertSame(403, $response->status);
        $this->assertFalse($response->isSuccessful());
        $this->assertSame(['error' => ['code' => 403]], $response->json());
    }

    public function testDoesNotFollowRedirects(): void
    {
        $response = (new StreamHttpClient())->send(HttpRequest::get(self::$baseUrl . '/redirect'));

        $this->assertSame(302, $response->status);
        $this->assertSame('/echo', $response->header('Location'));
    }

    public function testRefusedConnectionFails(): void
    {
        $this->expectException(HttpClientFailure::class);

        (new StreamHttpClient())->send(HttpRequest::get('http://127.0.0.1:' . self::freePort() . '/', [], 2.0));
    }

    public function testTimesOut(): void
    {
        $start = microtime(true);

        try {
            (new StreamHttpClient())->send(HttpRequest::get(self::$baseUrl . '/slow', [], 0.5));
            $this->fail('Expected a timeout.');
        } catch (HttpClientFailure $failure) {
            $this->assertStringContainsString('127.0.0.1', $failure->getMessage());
        }

        $this->assertLessThan(1.5, microtime(true) - $start);
    }

    public function testRejectsHeaderInjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HttpRequest::get(self::$baseUrl . '/echo', ['X-Test' => "a\r\nX-Injected: b"]);
    }
}
