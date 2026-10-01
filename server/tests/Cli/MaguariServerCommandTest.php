<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * Runs bin/maguari-server in a separate process, as an administrator would.
 * The commands that touch the database refuse root, so these tests need an
 * unprivileged user (scripts/test-ubuntu-22.04.sh runs PHPUnit as nobody).
 */
final class MaguariServerCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        if (posix_geteuid() === 0) {
            $this->fail('Run the tests as an unprivileged user: maguari-server refuses root for database commands.');
        }

        $this->directory = sys_get_temp_dir() . '/maguari-cli-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    /**
     * @param string[] $args
     * @return array{int, string, string} exit code, stdout and stderr
     */
    private function runCommand(string $database, array $args): array
    {
        $command = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/bin/maguari-server'], $args);
        $environment = getenv() + [];
        $environment['MAGUARI_DATABASE'] = $database;
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function assertOneCleanLine(string $stderr, string $path): void
    {
        $this->assertSame(1, substr_count($stderr, "\n"), $stderr);
        $this->assertStringEndsWith("\n", $stderr);
        $this->assertStringContainsString($path, $stderr);
        $this->assertStringContainsString('MAGUARI_DATABASE', $stderr);
        $this->assertStringNotContainsString('Stack trace', $stderr);
        $this->assertStringNotContainsString('Warning', $stderr);
        $this->assertStringNotContainsString('.php', $stderr);
    }

    /**
     * @return array<string, array{string[]}>
     */
    public static function databaseCommands(): array
    {
        return [
            'migrate' => [['migrate']],
            'issue-setup-token' => [['issue-setup-token', '--base-url=https://maguari.example.com']],
        ];
    }

    /**
     * @dataProvider databaseCommands
     * @param string[] $args
     */
    public function testDirectoryThatCannotBeCreated(array $args): void
    {
        // A regular file where the directory should be: mkdir fails even for root.
        file_put_contents($this->directory . '/not-a-directory', '');
        $path = $this->directory . '/not-a-directory/maguari.sqlite';

        [$status, $stdout, $stderr] = $this->runCommand($path, $args);

        $this->assertSame(1, $status);
        $this->assertSame('', $stdout);
        $this->assertOneCleanLine($stderr, $path);
        $this->assertStringContainsString('could not create directory', $stderr);
    }

    /**
     * @dataProvider databaseCommands
     * @param string[] $args
     */
    public function testFileThatIsNotADatabase(array $args): void
    {
        $path = $this->directory . '/maguari.sqlite';
        file_put_contents($path, str_repeat('not a database ', 100));

        [$status, $stdout, $stderr] = $this->runCommand($path, $args);

        $this->assertSame(1, $status);
        $this->assertSame('', $stdout);
        $this->assertOneCleanLine($stderr, $path);
    }

    public function testReadOnlyDatabase(): void
    {
        $path = $this->directory . '/maguari.sqlite';
        [$status] = $this->runCommand($path, ['migrate']);
        $this->assertSame(0, $status);
        // A new migration cannot be simulated, so make the next write fail
        // through a read-only file and a pending setup token write.
        chmod($path, 0400);

        [$status, $stdout, $stderr] = $this->runCommand($path, ['issue-setup-token', '--base-url=https://maguari.example.com']);

        chmod($path, 0600);
        $this->assertSame(1, $status);
        $this->assertOneCleanLine($stderr, $path);
        $this->assertStringNotContainsString('auth/setup?token=', $stdout);
    }

    public function testMigrateSucceeds(): void
    {
        $path = $this->directory . '/maguari.sqlite';

        [$status, $stdout, $stderr] = $this->runCommand($path, ['migrate']);

        $this->assertSame(0, $status, $stderr);
        $this->assertStringContainsString("Database {$path} is up to date.", $stdout);
        $this->assertSame('', $stderr);
        $this->assertSame(0600, fileperms($path) & 0777);
    }
}
