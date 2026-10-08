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
     * @param array<string, string> $extraEnvironment
     * @return array{int, string, string} exit code, stdout and stderr
     */
    private function runCommand(string $database, array $args, array $extraEnvironment = []): array
    {
        $command = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/bin/maguari-server'], $args);
        $environment = $extraEnvironment + getenv();
        $environment['MAGUARI_DATABASE'] = $database;
        $environment['MAGUARI_SECRET_KEY_FILE'] = $this->directory . '/secret.key';
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

    private function storedBaseUrl(string $path): string|false
    {
        $pdo = new \PDO('sqlite:' . $path);

        return $pdo->query("SELECT value FROM access_settings WHERE name = 'base_url'")->fetchColumn();
    }

    public function testCreateSecretKey(): void
    {
        $key = $this->directory . '/secret.key';

        [$status, $stdout, $stderr] = $this->runCommand($this->directory . '/maguari.sqlite', ['create-secret-key']);

        $this->assertSame(0, $status, $stderr);
        $this->assertStringContainsString("Created the secret key file {$key} (mode 0600).", $stdout);
        $this->assertSame(0600, fileperms($key) & 0777);
        $this->assertSame(32, filesize($key));
        $contents = file_get_contents($key);

        [$status, $stdout] = $this->runCommand($this->directory . '/maguari.sqlite', ['create-secret-key']);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('already exists. It was not changed', $stdout);
        $this->assertSame($contents, file_get_contents($key));
    }

    public function testCreateSecretKeyInADirectoryThatCannotBeCreated(): void
    {
        file_put_contents($this->directory . '/not-a-directory', '');
        $command = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/bin/maguari-server'], ['create-secret-key']);
        $environment = getenv() + [];
        $environment['MAGUARI_SECRET_KEY_FILE'] = $this->directory . '/not-a-directory/secret.key';
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        $this->assertSame(1, proc_close($process));
        $this->assertSame(1, substr_count($stderr, "\n"), $stderr);
        $this->assertStringContainsString('not-a-directory/secret.key', $stderr);
        $this->assertStringNotContainsString('Stack trace', $stderr);
    }

    public function testCheckSeedConfigReadsTheDevelopmentPathAndHidesThePassword(): void
    {
        $seedFile = $this->directory . '/seed.ini';
        file_put_contents($seedFile, "[administrator]\nname = \"Jane Doe\"\nemail = \"jane@example.com\"\n\n"
            . "[smtp]\nhost = \"smtp.gmail.com\"\nusername = \"alerts@example.com\"\npassword = \"abcd efgh ijkl mnop\"\n");

        [$status, $stdout, $stderr] = $this->runCommand($this->directory . '/maguari.sqlite', ['check-seed-config'], ['MAGUARI_SEED_FILE' => $seedFile]);

        $this->assertSame(0, $status, $stderr);
        $this->assertStringContainsString("Seed config file: {$seedFile}\n", $stdout);
        $this->assertStringContainsString("SMTP host: smtp.gmail.com\n", $stdout);
        $this->assertStringContainsString("SMTP password: (set, hidden)\n", $stdout);
        $this->assertStringNotContainsString('abcd', $stdout . $stderr);
    }

    public function testIssueSetupTokenRecordsTheBaseUrl(): void
    {
        $path = $this->directory . '/maguari.sqlite';

        [$status, $stdout, $stderr] = $this->runCommand($path, ['issue-setup-token', '--base-url=https://Maguari.Example.com/']);

        $this->assertSame(0, $status, $stderr);
        $this->assertStringContainsString('https://maguari.example.com/auth/setup?token=', $stdout);
        $this->assertSame('https://maguari.example.com', $this->storedBaseUrl($path));
    }

    public function testSetBaseUrl(): void
    {
        $path = $this->directory . '/maguari.sqlite';

        [$status, $stdout, $stderr] = $this->runCommand($path, ['set-base-url', '--base-url=http://localhost:8080']);

        $this->assertSame(0, $status, $stderr);
        $this->assertStringContainsString("The server's address is now http://localhost:8080.", $stdout);
        $this->assertSame('http://localhost:8080', $this->storedBaseUrl($path));
    }

    /**
     * @return array<string, array{string[]}>
     */
    public static function invalidBaseUrls(): array
    {
        return [
            'plain http elsewhere' => [['set-base-url', '--base-url=http://maguari.example.com']],
            'a path' => [['set-base-url', '--base-url=https://maguari.example.com/maguari']],
            'missing' => [['set-base-url']],
            'issue-setup-token with plain http' => [['issue-setup-token', '--base-url=http://127.0.0.1']],
        ];
    }

    /**
     * @dataProvider invalidBaseUrls
     * @param string[] $args
     */
    public function testRejectsInvalidBaseUrls(array $args): void
    {
        $path = $this->directory . '/maguari.sqlite';

        [$status, $stdout, $stderr] = $this->runCommand($path, $args);

        $this->assertSame(1, $status);
        $this->assertSame('', $stdout);
        $this->assertSame(1, substr_count($stderr, "\n"), $stderr);
        $this->assertFileDoesNotExist($path);
    }

    public function testRunDailyJobNeedsAMigratedDatabase(): void
    {
        $path = $this->directory . '/maguari.sqlite';

        [$status, $stdout, $stderr] = $this->runCommand($path, ['run-daily-job']);

        $this->assertSame(1, $status);
        $this->assertSame('', $stdout);
        $this->assertSame("The database {$path} is missing or not up to date. Run maguari-server migrate first.\n", $stderr);
        $this->assertFileDoesNotExist($path);
    }

    public function testRunDailyJobWithNoInstancesIsManualByDefault(): void
    {
        $path = $this->directory . '/maguari.sqlite';
        $this->runCommand($path, ['migrate']);

        [$status, $stdout, $stderr] = $this->runCommand($path, ['run-daily-job']);

        $this->assertSame(0, $status, $stderr);
        $this->assertSame("No instances to check.\nDaily job finished: 0 passed, 0 failed, 0 not checked.\n", $stdout);
        $this->assertSame('', $stderr);
        $pdo = new \PDO('sqlite:' . $path);
        $this->assertSame('manual', $pdo->query('SELECT triggered_by FROM monitoring_daily_job_runs')->fetchColumn());
    }

    public function testRunDailyJobFromTheTimerIsScheduled(): void
    {
        $path = $this->directory . '/maguari.sqlite';
        $this->runCommand($path, ['migrate']);

        [$status, , $stderr] = $this->runCommand($path, ['run-daily-job', '--scheduled']);

        $this->assertSame(0, $status, $stderr);
        $pdo = new \PDO('sqlite:' . $path);
        $this->assertSame('scheduled', $pdo->query('SELECT triggered_by FROM monitoring_daily_job_runs')->fetchColumn());
    }

    public function testTheTimerPassesScheduled(): void
    {
        $service = (string) file_get_contents(dirname(__DIR__, 2) . '/systemd/maguari-server-daily-job.service');

        $this->assertStringContainsString("\nExecStart=/usr/bin/maguari-server run-daily-job --scheduled\n", $service);
    }

    public function testRunDailyJobRejectsUnknownOptions(): void
    {
        $path = $this->directory . '/maguari.sqlite';
        $this->runCommand($path, ['migrate']);

        [$status, $stdout, $stderr] = $this->runCommand($path, ['run-daily-job', '--schedule']);

        $this->assertSame(1, $status);
        $this->assertSame('', $stdout);
        $this->assertSame("Usage: maguari-server run-daily-job [--scheduled]\n", $stderr);
        $this->assertFalse((new \PDO('sqlite:' . $path))->query('SELECT 1 FROM monitoring_daily_job_runs')->fetchColumn());
    }

    public function testRunDailyJobThatFailsPrintsOneLineAndMarksTheRunFailed(): void
    {
        $path = $this->directory . '/maguari.sqlite';
        $this->runCommand($path, ['migrate']);
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->exec("INSERT INTO fleet_projects (id, gcp_project_id, created_at) VALUES (1, 'my-project', 0)");
        $pdo->exec("INSERT INTO fleet_instances (project_id, gcp_instance_id, zone, name, picked_at) VALUES (1, '1', 'us-east1-b', 'web', 0)");
        // Storing the results fails.
        $pdo->exec('DROP TABLE monitoring_check_results');

        [$status, $stdout, $stderr] = $this->runCommand($path, ['run-daily-job'], [
            'MAGUARI_GCP_CREDENTIALS' => 'application-default',
            'HOME' => $this->directory,
        ]);

        $this->assertSame(1, $status);
        $this->assertSame('', $stdout);
        $this->assertStringStartsWith('The daily job failed: PDOException: ', $stderr);
        $this->assertSame(1, substr_count($stderr, "\n"), $stderr);
        $this->assertSame(1, (int) $pdo->query('SELECT failed FROM monitoring_daily_job_runs')->fetchColumn());
    }

    public function testRunDailyJobReportsEachInstanceAndExitsZeroWhenChecksCannotRun(): void
    {
        $path = $this->directory . '/maguari.sqlite';
        $this->runCommand($path, ['migrate']);
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->exec("INSERT INTO fleet_projects (id, gcp_project_id, created_at) VALUES (1, 'my-project', 0)");
        $pdo->exec("INSERT INTO fleet_instances (project_id, gcp_instance_id, zone, name, picked_at) VALUES (1, '1', 'us-east1-b', 'web', 0)");

        // No gcloud credentials in this HOME, so no request is ever sent.
        [$status, $stdout, $stderr] = $this->runCommand($path, ['run-daily-job'], [
            'MAGUARI_GCP_CREDENTIALS' => 'application-default',
            'HOME' => $this->directory,
        ]);

        $this->assertSame(0, $status, $stderr);
        $this->assertStringStartsWith('my-project/us-east1-b/web: Disk size: Not checked. Maguari could not obtain Google Cloud credentials.', $stdout);
        $this->assertStringEndsWith("\nDaily job finished: 0 passed, 0 failed, 1 not checked.\n", $stdout);
        $this->assertSame('', $stderr);
    }

    public function testRunDailyJobRefusesWhileRunning(): void
    {
        $path = $this->directory . '/maguari.sqlite';
        $this->runCommand($path, ['migrate']);
        $startedAt = time() - 60;
        (new \PDO('sqlite:' . $path))->exec("INSERT INTO monitoring_daily_job_runs (triggered_by, started_at) VALUES ('manual', {$startedAt})");

        [$status, $stdout, $stderr] = $this->runCommand($path, ['run-daily-job']);

        $this->assertSame(1, $status);
        $this->assertSame('', $stdout);
        $this->assertSame('The daily job is already running, started at ' . gmdate('Y-m-d H:i', $startedAt) . " UTC.\n", $stderr);
    }
}
