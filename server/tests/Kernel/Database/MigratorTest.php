<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Kernel\Database;

use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Kernel\Database\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/maguari-migrator-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/src/Example/Migrations', 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    private function database(): Database
    {
        $database = new Database($this->directory . '/data/maguari.sqlite');
        $database->create();

        return $database;
    }

    public function testMissingDatabaseIsNotUpToDate(): void
    {
        $this->assertFalse((new Migrator(new Database($this->directory . '/missing.sqlite')))->isUpToDate());
    }

    public function testAppliesTheServerMigrationsOnce(): void
    {
        $database = $this->database();
        $migrator = new Migrator($database);

        $this->assertFalse($migrator->isUpToDate());
        $this->assertSame([
            'Access/0001_access_administrators.sql',
            'Access/0002_access_setup_tokens.sql',
            'Access/0003_access_login_attempts.sql',
            'Access/0004_access_settings.sql',
            'Clients/0001_clients_enrollment_tokens.sql',
            'Clients/0002_clients_clients.sql',
            'Clients/0003_clients_nonces.sql',
            'Fleet/0001_fleet_projects.sql',
            'Fleet/0002_fleet_instances.sql',
            'Monitoring/0001_monitoring_metric_runs.sql',
            'Monitoring/0002_monitoring_daily_job.sql',
            'Monitoring/0003_monitoring_daily_job_failed.sql',
            'Monitoring/0004_monitoring_check_results_subject.sql',
            'Monitoring/0005_monitoring_certificate_hostnames.sql',
            'Notifications/0001_notifications_smtp_settings.sql',
        ], $migrator->migrate());
        $this->assertTrue($migrator->isUpToDate());
        $this->assertSame([], $migrator->migrate());
    }

    public function testAppliesOnlyNewMigrations(): void
    {
        $database = $this->database();
        $migrator = new Migrator($database, $this->directory . '/src');
        file_put_contents($this->directory . '/src/Example/Migrations/0001_first.sql', 'CREATE TABLE example_first (id INTEGER);');

        $this->assertSame(['Example/0001_first.sql'], $migrator->migrate());

        file_put_contents($this->directory . '/src/Example/Migrations/0002_second.sql', 'CREATE TABLE example_second (id INTEGER);');

        $this->assertFalse($migrator->isUpToDate());
        $this->assertSame(['Example/0002_second.sql'], $migrator->migrate());
    }

    public function testFailedMigrationIsRolledBackAndNotRecorded(): void
    {
        $database = $this->database();
        $migrator = new Migrator($database, $this->directory . '/src');
        file_put_contents(
            $this->directory . '/src/Example/Migrations/0001_broken.sql',
            'CREATE TABLE example_partial (id INTEGER); THIS IS NOT SQL;',
        );

        try {
            $migrator->migrate();
            $this->fail('A broken migration must throw.');
        } catch (\PDOException) {
        }

        $this->assertFalse($migrator->isUpToDate());
        $this->assertFalse(
            $database->pdo()->query("SELECT 1 FROM sqlite_master WHERE name = 'example_partial'")->fetchColumn(),
        );
    }

    public function testDatabaseSettingsAndPermissions(): void
    {
        $database = $this->database();
        $pdo = $database->pdo();

        $this->assertSame('wal', $pdo->query('PRAGMA journal_mode')->fetchColumn());
        $this->assertSame(5000, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
        $this->assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        $this->assertSame(0600, fileperms($database->path()) & 0777);
        $this->assertSame(0700, fileperms(dirname($database->path())) & 0777);
    }

    public function testNeverCreatesTheDatabaseWhenOpening(): void
    {
        $database = new Database($this->directory . '/missing.sqlite');

        $this->expectException(\RuntimeException::class);

        try {
            $database->pdo();
        } finally {
            $this->assertFileDoesNotExist($this->directory . '/missing.sqlite');
        }
    }
}
