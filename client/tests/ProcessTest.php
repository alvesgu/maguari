<?php

declare(strict_types=1);

namespace Maguari\Client\Tests;

use Maguari\Client\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Runs bin/maguari-client from a copy of client/ and shared/ alone, as the
 * client package will ship, so nothing can come from server/vendor/.
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
