<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Kernel\HttpProbe;

use Maguari\Server\Kernel\HttpProbe\HttpProbeFailure;
use Maguari\Server\Kernel\HttpProbe\HttpProbeFailureKind;
use Maguari\Server\Kernel\HttpProbe\StreamHttpProbe;
use Maguari\Server\Tests\Support\TestCertificateAuthority;
use PHPUnit\Framework\TestCase;

/**
 * Against a local server started by the test (tests/fixtures/http-server.php),
 * with certificates made at test time. "localhost" resolves to the server's
 * 127.0.0.1.
 */
final class StreamHttpProbeTest extends TestCase
{
    private string $directory;
    private TestCertificateAuthority $authority;
    private string $caFile;
    /** @var list<resource> */
    private array $servers = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/maguari-probe-test-' . bin2hex(random_bytes(8));
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
     * @param ?string $certificate a certificate and key PEM for TLS
     * @return int the port
     */
    private function server(string $mode, ?string $certificate = null): int
    {
        $arguments = [PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/http-server.php', $mode];

        if ($certificate !== null) {
            $arguments[] = $this->directory . '/server-' . count($this->servers) . '.pem';
            file_put_contents($arguments[3], $certificate);
        }

        $process = proc_open($arguments, [1 => ['pipe', 'w']], $pipes);
        $this->servers[] = $process;

        return (int) fgets($pipes[1]);
    }

    private function probe(string $url, float $totalSeconds = 5.0): HttpProbeFailure|int
    {
        try {
            return (new StreamHttpProbe($this->caFile))->probe($url, 'Maguari test', 2.0, $totalSeconds)->status;
        } catch (HttpProbeFailure $failure) {
            return $failure;
        }
    }

    private function assertFailure(HttpProbeFailureKind $kind, HttpProbeFailure|int $outcome): void
    {
        $this->assertInstanceOf(HttpProbeFailure::class, $outcome, 'Expected a failure.');
        $this->assertSame($kind, $outcome->kind, $outcome->getMessage());
    }

    public function testReadsTheStatusOverPlainHttp(): void
    {
        $port = $this->server('503');

        $this->assertSame(503, $this->probe("http://localhost:{$port}/health?full=1"));
    }

    public function testReadsTheStatusOverVerifiedTls(): void
    {
        $port = $this->server('200', $this->authority->serverCertificate(['localhost'], 60)['pem']);
        $answer = (new StreamHttpProbe($this->caFile))->probe("https://localhost:{$port}/", 'Maguari test', 2.0, 5.0);

        $this->assertSame(200, $answer->status);
        $this->assertGreaterThanOrEqual(0, $answer->elapsedMs);
        $this->assertLessThan(5000, $answer->elapsedMs);
    }

    public function testRedirectsAreNotFollowed(): void
    {
        $port = $this->server('302');

        $this->assertSame(302, $this->probe("http://localhost:{$port}/"));
    }

    public function testAnUntrustedOrMismatchedCertificateFails(): void
    {
        $selfSigned = $this->server('200', $this->authority->serverCertificate(['localhost'], 60, selfSigned: true)['pem']);
        $otherName = $this->server('200', $this->authority->serverCertificate(['www.example.com'], 60)['pem']);

        $this->assertFailure(HttpProbeFailureKind::TlsFailed, $this->probe("https://localhost:{$selfSigned}/"));
        $this->assertFailure(HttpProbeFailureKind::TlsFailed, $this->probe("https://localhost:{$otherName}/"));
    }

    public function testARefusedConnection(): void
    {
        // A port that was free a moment ago, with nothing listening.
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $this->assertFailure(HttpProbeFailureKind::Refused, $this->probe('http://localhost:' . substr($name, strrpos($name, ':') + 1) . '/'));
    }

    public function testANameThatDoesNotResolve(): void
    {
        $this->assertFailure(HttpProbeFailureKind::NameNotResolved, $this->probe('http://maguari-test.invalid/'));
    }

    public function testASilentServerTimesOutWithinTheLimit(): void
    {
        $port = $this->server('silent');
        $started = microtime(true);

        $this->assertFailure(HttpProbeFailureKind::TimedOut, $this->probe("http://localhost:{$port}/", 1.0));
        $this->assertLessThan(2.0, microtime(true) - $started);
    }

    public function testAHandshakeThatNeverStartsTimesOut(): void
    {
        $port = $this->server('silent');

        $this->assertFailure(HttpProbeFailureKind::TimedOut, $this->probe("https://localhost:{$port}/", 1.0));
    }

    public function testSomethingThatIsNotHttp(): void
    {
        $garbage = $this->server('garbage');
        $closed = $this->server('close');

        $this->assertFailure(HttpProbeFailureKind::BadResponse, $this->probe("http://localhost:{$garbage}/"));
        $this->assertFailure(HttpProbeFailureKind::BadResponse, $this->probe("http://localhost:{$closed}/"));
    }
}
