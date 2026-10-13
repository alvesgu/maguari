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
    private const CHECKOUT_HINT = 'In a source checkout, load the development environment with source scripts/dev-env.sh, '
        . 'then run server/bin/maguari-server migrate.';

    private const INSTALLED_HINT = 'Run sudo -u maguari-server maguari-server migrate.';

    private string $directory;

    private string $checkoutScript;

    protected function setUp(): void
    {
        if (posix_geteuid() === 0) {
            $this->fail('Run the tests as an unprivileged user: maguari-server refuses root for database commands.');
        }

        $this->directory = sys_get_temp_dir() . '/maguari-cli-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->checkoutScript = $this->scriptIn($this->directory . '/checkout', true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
    }

    /**
     * @param string[] $args
     * @param array<string, string> $extraEnvironment
     * @param string|null $script the command to run, from a source checkout by default
     * @return array{int, string, string} exit code, stdout and stderr
     */
    private function runCommand(string $database, array $args, array $extraEnvironment = [], ?string $script = null): array
    {
        $command = array_merge([PHP_BINARY, $script ?? $this->checkoutScript], $args);
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

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }

        rmdir($path);
    }

    /**
     * A copy of bin/maguari-server in $root/server/bin, using this checkout's
     * vendor/. The command gives development hints only when
     * $root/scripts/dev-env.sh exists, so the tests build both layouts instead
     * of depending on where they run (the Ubuntu 22.04 container copies only
     * server/, shared/ and client/).
     */
    private function scriptIn(string $root, bool $checkout): string
    {
        mkdir($root . '/server/bin', 0700, true);
        copy(dirname(__DIR__, 2) . '/bin/maguari-server', $root . '/server/bin/maguari-server');
        symlink(dirname(__DIR__, 2) . '/vendor', $root . '/server/vendor');

        if ($checkout) {
            mkdir($root . '/scripts', 0700);
            touch($root . '/scripts/dev-env.sh');
        }

        return $root . '/server/bin/maguari-server';
    }

    /**
     * The command as an installed package would have it: no
     * scripts/dev-env.sh two levels up.
     */
    private function installedScript(): string
    {
        return $this->scriptIn($this->directory . '/install', false);
    }

    /**
     * A migrated database and a secret key, as the tick needs.
     */
    private function prepareForTick(string $database): void
    {
        $this->runCommand($database, ['migrate']);
        $this->runCommand($database, ['create-secret-key']);
    }

    private function assertOneCleanLine(string $stderr, string $path): void
    {
        $this->assertSame(1, substr_count($stderr, "\n"), $stderr);
        $this->assertStringEndsWith("\n", $stderr);
        $this->assertStringContainsString($path, $stderr);
        $this->assertStringContainsString(self::CHECKOUT_HINT, $stderr);
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
        $this->assertSame("The database {$path} is missing or not up to date. " . self::CHECKOUT_HINT . "\n", $stderr);
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

    /**
     * @return array<string, array{string[]}>
     */
    public static function commandsNeedingAMigratedDatabase(): array
    {
        return [
            'run-daily-job' => [['run-daily-job']],
            'tick' => [['tick']],
        ];
    }

    /**
     * @dataProvider commandsNeedingAMigratedDatabase
     * @param string[] $args
     */
    public function testAnInstalledCommandSuggestsMigratingAsTheAppUser(array $args): void
    {
        $path = $this->directory . '/maguari.sqlite';

        [$status, $stdout, $stderr] = $this->runCommand($path, $args, [], $this->installedScript());

        $this->assertSame(1, $status);
        $this->assertSame('', $stdout);
        $this->assertSame("The database {$path} is missing or not up to date. " . self::INSTALLED_HINT . "\n", $stderr);
    }

    public function testAnInstalledCommandNeverGivesADevelopmentHintWhenTheDatabaseCannotBeCreated(): void
    {
        file_put_contents($this->directory . '/not-a-directory', '');
        $path = $this->directory . '/not-a-directory/maguari.sqlite';

        [$status, $stdout, $stderr] = $this->runCommand($path, ['migrate'], [], $this->installedScript());

        $this->assertSame(1, $status);
        $this->assertSame('', $stdout);
        $this->assertStringStartsWith("Cannot use the database at {$path}: could not create directory", $stderr);
        $this->assertStringEndsWith(' ' . self::INSTALLED_HINT . "\n", $stderr);
        $this->assertStringNotContainsString('dev-env', $stderr);
        $this->assertStringNotContainsString('MAGUARI_DATABASE', $stderr);
    }

    public function testAnInstalledCommandNeverGivesADevelopmentHintOnADatabaseError(): void
    {
        $path = $this->directory . '/maguari.sqlite';
        file_put_contents($path, str_repeat('not a database ', 100));

        [$status, , $stderr] = $this->runCommand($path, ['migrate'], [], $this->installedScript());

        $this->assertSame(1, $status);
        $this->assertSame(1, substr_count($stderr, "\n"), $stderr);
        $this->assertStringEndsWith(' ' . self::INSTALLED_HINT . "\n", $stderr);
        $this->assertStringNotContainsString('dev-env', $stderr);
    }

    public function testTickNeedsAMigratedDatabase(): void
    {
        $path = $this->directory . '/maguari.sqlite';

        [$status, $stdout, $stderr] = $this->runCommand($path, ['tick']);

        $this->assertSame(1, $status);
        $this->assertSame('', $stdout);
        $this->assertSame("The database {$path} is missing or not up to date. " . self::CHECKOUT_HINT . "\n", $stderr);
        $this->assertFileDoesNotExist($path);
    }

    public function testTickPrintsNothingWhenThereIsNothingToDo(): void
    {
        $path = $this->directory . '/maguari.sqlite';
        $this->prepareForTick($path);

        [$status, $stdout, $stderr] = $this->runCommand($path, ['tick']);

        $this->assertSame(0, $status, $stderr);
        $this->assertSame('', $stdout);
        $this->assertSame('', $stderr);
        $this->assertSame(0600, fileperms($path . '.tick-lock') & 0777);
    }

    public function testTickJudgesHeartbeatAgesBeforeDeliveringEvents(): void
    {
        $path = $this->directory . '/maguari.sqlite';
        $this->prepareForTick($path);
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->exec("INSERT INTO fleet_projects (id, gcp_project_id, created_at) VALUES (1, 'my-project', 0)");
        $pdo->exec("INSERT INTO fleet_instances (id, project_id, gcp_instance_id, zone, name, picked_at) VALUES (1, 1, '1', 'us-east1-b', 'web', 0)");
        $pdo->exec('INSERT INTO monitoring_heartbeats (instance_id, last_at) VALUES (1, ' . time() . ')');

        [$status, $stdout, $stderr] = $this->runCommand($path, ['tick']);

        $this->assertSame(0, $status, $stderr);
        $this->assertSame("Delivered event 1 (monitoring.check_passed).\n", $stdout);
        $this->assertSame('', $stderr);
        $this->assertSame('pass', $pdo->query("SELECT outcome FROM monitoring_check_states WHERE check_name = 'heartbeat_age'")->fetchColumn());

        [$status, $stdout] = $this->runCommand($path, ['tick']);

        $this->assertSame(0, $status);
        $this->assertSame('', $stdout, 'An unchanged outcome writes no event.');
    }

    public function testTickReconcilesOnceADayAndPrintsWhatItFound(): void
    {
        $path = $this->directory . '/maguari.sqlite';
        $this->prepareForTick($path);
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->exec("INSERT INTO fleet_projects (id, gcp_project_id, created_at) VALUES (1, 'my-project', 0)");
        $pdo->exec("INSERT INTO fleet_instances (id, project_id, gcp_instance_id, zone, name, picked_at) VALUES (1, 1, '1', 'us-east1-b', 'web', 0)");
        // A failure whose event was lost.
        $pdo->exec("INSERT INTO monitoring_check_states (instance_id, check_name, subject, outcome, since) VALUES (1, 'disk_size', '', 'fail', 2)");

        [$status, $stdout, $stderr] = $this->runCommand($path, ['tick']);

        $this->assertSame(0, $status, $stderr);
        $this->assertSame(
            "Reconciliation opened incident 1 on my-project/us-east1-b/web (disk_size): its check is failing but no incident was open.\n"
                . "Delivered event 1 (remediation.incident_opened).\n",
            $stdout,
        );

        $this->assertSame(
            [['incident_id' => 1, 'check_name' => 'disk_size', 'resolved_at' => null]],
            $pdo->query('SELECT incident_id, check_name, resolved_at FROM notifications_notifications')->fetchAll(\PDO::FETCH_ASSOC),
            'Notifications subscribes in the same tick.',
        );

        [, $stdout] = $this->runCommand($path, ['tick']);

        $this->assertSame('', $stdout, 'Once a day.');
    }

    public function testTickReconcilesNotificationsWithOpenIncidents(): void
    {
        $path = $this->directory . '/maguari.sqlite';
        $this->prepareForTick($path);
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->exec("INSERT INTO fleet_projects (id, gcp_project_id, created_at) VALUES (1, 'my-project', 0)");
        $pdo->exec("INSERT INTO fleet_instances (id, project_id, gcp_instance_id, zone, name, picked_at) VALUES (1, 1, '1', 'us-east1-b', 'web', 0)");
        // An incident Notifications never heard of, which Monitoring agrees is failing.
        $pdo->exec("INSERT INTO monitoring_check_states (instance_id, check_name, subject, outcome, since) VALUES (1, 'disk_size', '', 'fail', 2)");
        $pdo->exec("INSERT INTO remediation_incidents (instance_id, check_name, subject, opened_at) VALUES (1, 'disk_size', '', 2)");

        [$status, $stdout, $stderr] = $this->runCommand($path, ['tick']);

        $this->assertSame(0, $status, $stderr);
        $this->assertSame(
            "Reconciliation created notification 1 of incident 1 on my-project/us-east1-b/web (disk_size): its incident is open but had none.\n",
            $stdout,
        );
    }

    public function testTickDeliversPendingEventsAndPrunesOldOnes(): void
    {
        $path = $this->directory . '/maguari.sqlite';
        $this->prepareForTick($path);
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->exec("INSERT INTO kernel_events (type, payload, occurred_at, delivered_at) VALUES ('monitoring.check_passed', '{}', 1, 1)");
        $pdo->exec("INSERT INTO kernel_events (type, payload, occurred_at) VALUES ('monitoring.check_failed', "
            . "'{\"instance_id\":1,\"check_name\":\"disk_size\",\"subject\":\"\",\"detail\":\"A sentence.\"}', 2)");
        $pdo->exec("INSERT INTO monitoring_check_states (instance_id, check_name, subject, outcome, since) VALUES (1, 'disk_size', '', 'fail', 2)");

        [$status, $stdout, $stderr] = $this->runCommand($path, ['tick']);

        $this->assertSame(0, $status, $stderr);
        // Remediation opens an incident, whose event is delivered in the same tick.
        $this->assertSame("Delivered event 2 (monitoring.check_failed).\nDelivered event 3 (remediation.incident_opened).\n", $stdout);
        // Its alert email cannot be sent without SMTP settings, which does not fail the tick.
        $this->assertSame("Gave up on the opened alert of incident 1 on instance 1 (disk_size): Email is not set up.\n", $stderr);
        $this->assertSame(
            [['id' => 2, 'pending' => 0], ['id' => 3, 'pending' => 0]],
            $pdo->query('SELECT id, delivered_at IS NULL AS pending FROM kernel_events')->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    public function testTickReportsAFailedEventOnStderr(): void
    {
        $path = $this->directory . '/maguari.sqlite';
        $this->prepareForTick($path);
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->exec("INSERT INTO kernel_events (type, payload, occurred_at) VALUES ('monitoring.check_failed', 'not json', 2)");

        [$status, $stdout, $stderr] = $this->runCommand($path, ['tick']);

        $this->assertSame(1, $status);
        $this->assertSame('', $stdout);
        $this->assertStringStartsWith('The event 1 (monitoring.check_failed) failed: JsonException: ', $stderr);
        $this->assertSame(1, substr_count($stderr, "\n"), $stderr);
        $this->assertFalse($pdo->query('SELECT 1 FROM notifications_reconciliations')->fetchColumn(), 'Not while an event is pending.');
    }

    public function testASecondTickWhileOneIsRunningDoesNothing(): void
    {
        $path = $this->directory . '/maguari.sqlite';
        $this->prepareForTick($path);
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->exec("INSERT INTO kernel_events (type, payload, occurred_at) VALUES ('monitoring.check_failed', '{}', 2)");
        $lock = fopen($path . '.tick-lock', 'c');
        $this->assertNotFalse($lock);
        $this->assertTrue(flock($lock, LOCK_EX));

        [$status, $stdout, $stderr] = $this->runCommand($path, ['tick']);

        flock($lock, LOCK_UN);
        fclose($lock);
        $this->assertSame(0, $status);
        $this->assertSame('', $stdout);
        $this->assertSame("Another tick is still running, so this one did nothing.\n", $stderr);
        $this->assertNull($pdo->query('SELECT delivered_at FROM kernel_events')->fetchColumn());
    }

    public function testTickNeedsTheSecretKey(): void
    {
        $path = $this->directory . '/maguari.sqlite';
        $this->runCommand($path, ['migrate']);

        [$status, $stdout, $stderr] = $this->runCommand($path, ['tick']);

        $this->assertSame(1, $status);
        $this->assertSame('', $stdout);
        $this->assertSame("maguari-server failed: The secret key file {$this->directory}/secret.key does not exist.\n", $stderr);
    }

    public function testTickRejectsArguments(): void
    {
        $path = $this->directory . '/maguari.sqlite';

        [$status, , $stderr] = $this->runCommand($path, ['tick', '--now']);

        $this->assertSame(1, $status);
        $this->assertSame("Usage: maguari-server tick\n", $stderr);
    }

    public function testTheTickTimerRunsTheTickEveryMinute(): void
    {
        $directory = dirname(__DIR__, 2) . '/systemd';
        $service = (string) file_get_contents($directory . '/maguari-server-tick.service');
        $timer = (string) file_get_contents($directory . '/maguari-server-tick.timer');

        $this->assertStringContainsString("\nExecStart=/usr/bin/maguari-server tick\n", $service);
        $this->assertStringContainsString("\nUser=maguari-server\n", $service);
        $this->assertStringContainsString("\nOnCalendar=*-*-* *:*:00 UTC\n", $timer);
        $this->assertDoesNotMatchRegularExpression('/^Persistent=/m', $timer);
    }
}
