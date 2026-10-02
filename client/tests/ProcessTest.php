<?php

declare(strict_types=1);

namespace Maguari\Client\Tests;

use Maguari\Client\Tests\Support\TemporaryDirectory;
use Maguari\Client\Tests\Support\TestCertificates;
use PHPUnit\Framework\TestCase;

/**
 * Runs bin/maguari-client and bin/maguari-certificate-scanner from a copy of
 * client/ and shared/ alone, as the client package will ship, so nothing can
 * come from server/vendor/.
 */
final class ProcessTest extends TestCase
{
    private TemporaryDirectory $directory;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
        $repository = dirname(__DIR__, 2);

        foreach (['client', 'shared'] as $component) {
            self::copy($repository . '/' . $component, $this->directory->path . '/' . $component);
        }
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    private static function copy(string $from, string $to): void
    {
        mkdir($to, 0700, true);

        foreach (scandir($from) ?: [] as $entry) {
            if (in_array($entry, ['.', '..', 'var', 'tests'], true)) {
                continue;
            }

            is_dir($from . '/' . $entry)
                ? self::copy($from . '/' . $entry, $to . '/' . $entry)
                : copy($from . '/' . $entry, $to . '/' . $entry);
        }
    }

    /**
     * @param string[] $args
     * @return array{int, string, string}
     */
    private function runClient(array $args): array
    {
        $environment = getenv() + [];
        $environment['MAGUARI_CLIENT_DIR'] = $this->directory->path . '/state';
        $command = array_merge([PHP_BINARY, $this->directory->path . '/client/bin/maguari-client'], $args);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->directory->path, $environment);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /**
     * @param string[] $args
     * @param array<string, string> $environment
     * @return array{int, string, string}
     */
    private function runScanner(array $args, array $environment): array
    {
        $command = array_merge([PHP_BINARY, $this->directory->path . '/client/bin/maguari-certificate-scanner'], $args);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->directory->path, $environment + getenv());
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    public function testTheScannerWritesTheCertificatesFile(): void
    {
        $certificate = TestCertificates::create('example.com', ['example.com', 'www.example.com'], 90);
        mkdir($this->directory->path . '/letsencrypt/live/example.com', 0700, true);
        file_put_contents($this->directory->path . '/letsencrypt/live/example.com/cert.pem', $certificate['pem']);
        mkdir($this->directory->path . '/state');
        $output = $this->directory->path . '/state/certificates.json';

        [$status, $stdout, $stderr] = $this->runScanner([], [
            'MAGUARI_LETSENCRYPT_DIR' => $this->directory->path . '/letsencrypt',
            'MAGUARI_CERTIFICATES_FILE' => $output,
        ]);

        $this->assertSame(0, $status, $stderr);
        $this->assertSame('Wrote 1 certificate(s) to ' . $output . ".\n", $stdout);
        $this->assertSame(
            [['name' => 'example.com', 'domains' => ['example.com', 'www.example.com'], 'expires_at' => $certificate['expires_at']]],
            json_decode((string) file_get_contents($output), true)['certificates'],
        );
    }

    public function testTheScannerFailsInOneLine(): void
    {
        $output = $this->directory->path . '/missing/certificates.json';

        [$status, $stdout, $stderr] = $this->runScanner([], [
            'MAGUARI_LETSENCRYPT_DIR' => $this->directory->path . '/letsencrypt',
            'MAGUARI_CERTIFICATES_FILE' => $output,
        ]);

        $this->assertSame(1, $status);
        $this->assertSame('', $stdout);
        $this->assertSame('Cannot write ' . $output . ': the directory ' . $this->directory->path . "/missing does not exist.\n", $stderr);
    }

    public function testTheScannerTakesNoArguments(): void
    {
        [$status, , $stderr] = $this->runScanner(['--help'], []);

        $this->assertSame(1, $status);
        $this->assertSame("Usage: maguari-certificate-scanner (no arguments)\n", $stderr);
    }

    public function testRunsWithoutTheServer(): void
    {
        [$status, $stdout, $stderr] = $this->runClient(['heartbeat']);

        $this->assertSame(1, $status);
        $this->assertSame('', $stdout);
        $this->assertSame(
            'This client is not enrolled: ' . $this->directory->path . "/state/credentials.json does not exist. Run maguari-client enroll first.\n",
            $stderr,
        );
    }

    public function testRefusesPlainHttpOutsideLocalhostWithoutSendingAnything(): void
    {
        [$status, , $stderr] = $this->runClient(['enroll', '--server=http://maguari.example.com', '--token=' . str_repeat('t', 43)]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('http:// is accepted only for localhost', $stderr);
        $this->assertStringNotContainsString('Stack trace', $stderr);
        $this->assertDirectoryDoesNotExist($this->directory->path . '/state');
    }

    public function testUnreachableServerIsOneLine(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);

        [$status, , $stderr] = $this->runClient(['enroll', '--server=http://localhost:' . $port, '--token=' . str_repeat('t', 43)]);

        $this->assertSame(1, $status);
        $this->assertStringStartsWith('No response from localhost: ', $stderr);
        $this->assertSame(1, substr_count($stderr, "\n"), $stderr);
    }

    public function testUsage(): void
    {
        [$status, , $stderr] = $this->runClient([]);

        $this->assertSame(1, $status);
        $this->assertStringStartsWith('Usage:', $stderr);
    }
}
