<?php

declare(strict_types=1);

namespace Maguari\Client\Tests;

use Maguari\Client\ClientFailure;
use Maguari\Client\StreamTransport;
use PHPUnit\Framework\TestCase;

/**
 * Runs against PHP's built-in server on 127.0.0.1, never the internet.
 */
final class StreamTransportTest extends TestCase
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
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    public function testPostsJsonWithHeaders(): void
    {
        $response = (new StreamTransport())->post(self::$baseUrl . '/echo', ['X-Maguari-Client' => 'abc'], '{"a":1}');

        $this->assertSame(200, $response->status);
        $this->assertSame(['method' => 'POST', 'contentType' => 'application/json', 'client' => 'abc', 'body' => '{"a":1}'], $response->json());
    }

    public function testNoContent(): void
    {
        $response = (new StreamTransport())->post(self::$baseUrl . '/no-content', [], '{}');

        $this->assertSame(204, $response->status);
        $this->assertSame('', $response->body);
    }

    public function testErrorBodiesAreRead(): void
    {
        $response = (new StreamTransport())->post(self::$baseUrl . '/unauthorized', [], '{}');

        $this->assertSame(401, $response->status);
        $this->assertSame(['error' => 'unauthorized'], $response->json());
    }

    public function testRedirectsAreNotFollowed(): void
    {
        $this->assertSame(302, (new StreamTransport())->post(self::$baseUrl . '/redirect', [], '{}')->status);
    }

    public function testConnectionRefused(): void
    {
        $this->expectException(ClientFailure::class);
        $this->expectExceptionMessageMatches('/^No response from 127\.0\.0\.1: /');

        (new StreamTransport())->post('http://127.0.0.1:' . self::freePort() . '/echo', [], '{}');
    }
}
