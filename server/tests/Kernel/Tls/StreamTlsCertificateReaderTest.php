<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Kernel\Tls;

use Maguari\Server\Kernel\Tls\StreamTlsCertificateReader;
use Maguari\Server\Kernel\Tls\TlsFailure;
use Maguari\Server\Tests\Support\TestCertificateAuthority;
use PHPUnit\Framework\TestCase;

/**
 * Against a local server started by the test (tests/fixtures/tls-server.php),
 * with certificates made at test time. "localhost" resolves to the server's
 * 127.0.0.1.
 */
final class StreamTlsCertificateReaderTest extends TestCase
{
    private string $directory;
    private TestCertificateAuthority $authority;
    private string $caFile;
    /** @var list<resource> */
    private array $servers = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/maguari-tls-test-' . bin2hex(random_bytes(8));
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

    /**
     * @param string $certificate a certificate and key PEM, or "silent"
     * @return int the port
     */
    private function server(string $certificate): int
    {
        $argument = $certificate;

        if ($certificate !== 'silent') {
            $argument = $this->directory . '/server-' . count($this->servers) . '.pem';
            file_put_contents($argument, $certificate);
        }

        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/tls-server.php', $argument], [1 => ['pipe', 'w']], $pipes);
        $this->servers[] = $process;

        return (int) fgets($pipes[1]);
    }

    private function reader(): StreamTlsCertificateReader
    {
        return new StreamTlsCertificateReader($this->caFile);
    }

    public function testReadsATrustedCertificate(): void
    {
        $certificate = $this->authority->serverCertificate(['localhost'], 60);
        $port = $this->server($certificate['pem']);

        $this->assertSame($certificate['expires_at'], $this->reader()->read('localhost', $port, true, 5.0)->expiresAt);
    }

    /**
     * @return array<string, array{list<string>, int, bool}>
     */
    public static function refusedCertificates(): array
    {
        return [
            'expired' => [['localhost'], -2, false],
            'another name' => [['other.example'], 60, false],
            'self-signed' => [['localhost'], 60, true],
        ];
    }

    /**
     * @dataProvider refusedCertificates
     * @param list<string> $domains
     */
    public function testARefusedCertificateIsStillReadWithoutVerification(array $domains, int $days, bool $selfSigned): void
    {
        $certificate = $this->authority->serverCertificate($domains, $days, $selfSigned);
        $port = $this->server($certificate['pem']);

        try {
            $this->reader()->read('localhost', $port, true, 5.0);
            $this->fail('The certificate was accepted.');
        } catch (TlsFailure $failure) {
            $this->assertSame('The TLS handshake with localhost failed.', $failure->getMessage());
        }

        $this->assertSame($certificate['expires_at'], $this->reader()->read('localhost', $port, false, 5.0)->expiresAt);
    }

    public function testAClosedPortFails(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $this->expectException(TlsFailure::class);
        $this->expectExceptionMessageMatches('/^No connection to localhost:\d+: /');
        $this->reader()->read('localhost', (int) substr($name, strrpos($name, ':') + 1), false, 5.0);
    }

    public function testAServerThatNeverAnswersTimesOut(): void
    {
        $port = $this->server('silent');
        $started = microtime(true);

        try {
            $this->reader()->read('localhost', $port, false, 1.0);
            $this->fail('No timeout.');
        } catch (TlsFailure $failure) {
            $this->assertSame('The TLS handshake with localhost timed out.', $failure->getMessage());
        }

        $elapsed = microtime(true) - $started;
        $this->assertGreaterThanOrEqual(1.0, $elapsed);
        $this->assertLessThan(2.0, $elapsed);
    }
}
