<?php

declare(strict_types=1);

namespace Maguari\Client\Tests;

use Maguari\Client\StreamLocalHttp;
use Maguari\Server\Tests\Support\TestCertificateAuthority;
use PHPUnit\Framework\TestCase;

/**
 * Against a local server started by the test (the server's
 * tests/fixtures/http-server.php, on 127.0.0.1 unless a test says otherwise),
 * with certificates made at test time.
 */
final class StreamLocalHttpTest extends TestCase
{
    private string $directory;
    private TestCertificateAuthority $authority;
    private string $caFile;
    /** @var list<resource> */
    private array $servers = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/maguari-local-http-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->authority = new TestCertificateAuthority($this->directory);
        $this->caFile = $this->directory . '/ca.pem';
        file_put_contents($this->caFile, $this->authority->certificatePem());
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            proc_terminate($server);
            proc_close($server);
        }

        array_map('unlink', glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    private function server(string $mode, ?string $certificate = null, string $address = '127.0.0.1'): int
    {
        $arguments = [PHP_BINARY, dirname(__DIR__, 2) . '/server/tests/fixtures/http-server.php', $mode];

        if ($certificate !== null) {
            $arguments[] = $this->directory . '/server-' . count($this->servers) . '.pem';
            file_put_contents($arguments[3], $certificate);
        }

        $this->servers[] = proc_open($arguments, [1 => ['pipe', 'w']], $pipes, null, ['HTTP_SERVER_ADDRESS' => $address] + getenv());

        return (int) fgets($pipes[1]);
    }

    /**
     * @return array{status: int, ms: int}|string
     */
    private function request(string $url, string $hostName = '', float $timeout = 2.0): array|string
    {
        return (new StreamLocalHttp($this->caFile, $timeout))->request($url, $hostName);
    }

    public function testReadsTheStatusAndTheTime(): void
    {
        $port = $this->server('503');
        $answer = $this->request("http://127.0.0.1:{$port}/health?full=1");

        $this->assertIsArray($answer);
        $this->assertSame(503, $answer['status']);
        $this->assertLessThan(2000, $answer['ms']);
        $this->assertSame(302, $this->request('http://localhost:' . $this->server('302') . '/')['status'] ?? null, 'localhost is 127.0.0.1; redirects are not followed.');
    }

    public function testLocalhostIsIpv6LoopbackWhenIpv4Refuses(): void
    {
        $probe = @stream_socket_server('tcp://[::1]:0');

        if ($probe === false) {
            $this->markTestSkipped('This machine has no IPv6 loopback.');
        }

        fclose($probe);
        $port = $this->server('204', null, '[::1]');

        $this->assertSame(204, $this->request("http://localhost:{$port}/")['status'] ?? null);
        $this->assertSame(204, $this->request("http://[::1]:{$port}/")['status'] ?? null);
        $this->assertSame('refused', $this->request("http://127.0.0.1:{$port}/"), 'A literal address is never swapped.');
    }

    public function testSendsTheHostNameAsTheHostHeader(): void
    {
        $port = $this->server('host:geonode.example.com');

        $this->assertSame(200, $this->request("http://127.0.0.1:{$port}/", 'geonode.example.com')['status'] ?? null);
        $this->assertSame(421, $this->request("http://127.0.0.1:{$port}/")['status'] ?? null, 'Without one, the Host is the URL\'s.');
    }

    public function testVerifiesTheCertificateAgainstTheHostName(): void
    {
        $port = $this->server('200', $this->authority->serverCertificate(['geonode.example.com'], 60)['pem']);

        $this->assertSame(200, $this->request("https://127.0.0.1:{$port}/", 'geonode.example.com')['status'] ?? null);
        $this->assertSame('tls_failed', $this->request("https://127.0.0.1:{$port}/", 'other.example.com'));
    }

    public function testEachFailureHasItsReason(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $this->assertSame('refused', $this->request('http://127.0.0.1:' . substr($name, strrpos($name, ':') + 1) . '/'));
        $this->assertSame('bad_response', $this->request('http://127.0.0.1:' . $this->server('garbage') . '/'));
        $this->assertSame('bad_response', $this->request('http://127.0.0.1:' . $this->server('close') . '/'));

        $silent = $this->server('silent');
        $started = microtime(true);
        $this->assertSame('timeout', $this->request("http://127.0.0.1:{$silent}/", '', 0.5));
        $this->assertSame('timeout', $this->request("https://127.0.0.1:{$silent}/", 'geonode.example.com', 0.5));
        $this->assertLessThan(2.5, microtime(true) - $started);
    }
}
