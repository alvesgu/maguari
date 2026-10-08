<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Monitoring\Application;

use Maguari\Server\Fleet\Instance;
use Maguari\Server\Monitoring\Domain\CheckOutcome;
use Maguari\Server\Monitoring\Domain\CheckResult;
use Maguari\Server\Monitoring\Domain\DailyJobTrigger;
use Maguari\Server\Monitoring\Exception\DailyJobAlreadyRunning;
use Maguari\Server\Monitoring\Exception\DailyJobFailed;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

final class DailyJobTest extends TestCase
{
    private const GIB = 1024 ** 3;

    private TestEnvironment $environment;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
        $this->environment->http->queueJson(200, ['kind' => 'compute#instanceAggregatedList']);
        $this->environment->fleet->addProject('my-project');
    }

    protected function tearDown(): void
    {
        $this->environment->cleanUp();
    }

    private function pick(string $name): Instance
    {
        $zoneUrl = 'https://www.googleapis.com/compute/v1/projects/my-project/zones/us-east1-b';
        $this->environment->http->queueJson(200, ['id' => '1', 'name' => $name, 'zone' => $zoneUrl, 'status' => 'RUNNING']);

        return $this->environment->fleet->pickInstance(1, 'us-east1-b', $name);
    }

    /**
     * @param array<string, int> $bootDiskGb by instance name
     */
    private function queueListing(array $bootDiskGb): void
    {
        $instances = [];

        foreach ($bootDiskGb as $name => $sizeGb) {
            $instances[] = [
                'id' => '1', 'name' => $name, 'status' => 'RUNNING',
                'zone' => 'https://www.googleapis.com/compute/v1/projects/my-project/zones/us-east1-b',
                'disks' => [['deviceName' => $name, 'boot' => true, 'diskSizeGb' => (string) $sizeGb]],
            ];
        }

        $this->environment->http->queueJson(200, ['items' => ['zones/us-east1-b' => ['instances' => $instances]]]);
    }

    private function recordRootTotal(int $instanceId, int $bytes): void
    {
        $monitoring = $this->environment->monitoring;
        $monitoring->recordReadings($instanceId, $this->environment->clock->now(), $monitoring->parseReadings([
            ['metric' => 'disk_used_bytes:/', 'value' => intdiv($bytes, 2)],
            ['metric' => 'disk_total_bytes:/', 'value' => $bytes],
        ]));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $table): array
    {
        return $this->environment->database->pdo()->query("SELECT * FROM {$table} ORDER BY id")->fetchAll();
    }

    private function insertUnfinishedRun(int $startedAt): void
    {
        $this->environment->database->pdo()->prepare(
            "INSERT INTO monitoring_daily_job_runs (triggered_by, started_at) VALUES ('scheduled', ?)",
        )->execute([$startedAt]);
    }

    public function testChecksEveryPickedInstanceAndStoresTheResults(): void
    {
        $grown = $this->pick('grown');
        $fine = $this->pick('fine');
        $silent = $this->pick('silent');
        $this->recordRootTotal($grown->id, (int) (9.6 * self::GIB));
        $this->recordRootTotal($fine->id, (int) (14.5 * self::GIB));
        $this->environment->clock->advance(3600);
        $this->queueListing(['fine' => 15, 'grown' => 20, 'silent' => 10]);
        $at = $this->environment->clock->now();

        $results = $this->environment->monitoring->runDailyJob(DailyJobTrigger::Manual);

        // In the order of FleetApi::pickedInstances(): by name.
        $this->assertSame([$fine->id, $grown->id, $silent->id], array_map(static fn (CheckResult $result): int => $result->instanceId, $results));
        $this->assertSame(
            [CheckOutcome::Pass, CheckOutcome::Fail, CheckOutcome::NotChecked],
            array_map(static fn (CheckResult $result): CheckOutcome => $result->outcome, $results),
        );
        $this->assertSame('No disk readings in the last 24 hours.', $results[2]->detail);

        $this->assertSame([['id' => 1, 'triggered_by' => 'manual', 'started_at' => $at, 'finished_at' => $at, 'failed' => 0]], $this->rows('monitoring_daily_job_runs'));
        $stored = $this->rows('monitoring_check_results');
        $this->assertCount(3, $stored);
        $this->assertSame(
            ['id' => 2, 'job_run_id' => 1, 'instance_id' => $grown->id, 'check_name' => 'disk_size', 'outcome' => 'fail', 'detail' => $results[1]->detail, 'checked_at' => $at, 'subject' => ''],
            $stored[1],
        );
    }

    /**
     * @param array<string, int> $expiries by domain
     */
    private function recordCertificates(int $instanceId, array $expiries): void
    {
        $readings = [];

        foreach ($expiries as $domain => $expiresAt) {
            $readings[] = ['metric' => 'certificate_expires_at:' . $domain, 'value' => $expiresAt];
        }

        $monitoring = $this->environment->monitoring;
        $monitoring->recordReadings($instanceId, $this->environment->clock->now(), $monitoring->parseReadings($readings));
    }

    public function testChecksEachRecentlyReportedCertificate(): void
    {
        $web = $this->pick('web');
        $plain = $this->pick('plain');
        $day = 86_400;
        $now = $this->environment->clock->now();
        // Removed from the instance more than a day ago: it no longer counts.
        $this->recordCertificates($web->id, ['removed.example.com' => $now + 3 * $day]);
        $this->environment->clock->advance(3600);
        // Renewed: only the current run of a domain counts.
        $this->recordCertificates($web->id, ['www.example.com' => $now + 5 * $day, 'example.com' => $now + 60 * $day]);
        $this->environment->clock->advance(3600);
        $this->recordCertificates($web->id, ['www.example.com' => $now + 70 * $day, 'example.com' => $now + 60 * $day]);
        $this->environment->clock->advance(23 * 3600);
        $this->queueListing(['plain' => 10, 'web' => 10]);
        $at = $this->environment->clock->now();

        $results = $this->environment->monitoring->runDailyJob(DailyJobTrigger::Manual);

        // plain: disk size only, and no certificate result.
        // web: disk size, then its certificates in domain order.
        $this->assertSame(
            [[$plain->id, 'disk_size', ''], [$web->id, 'disk_size', ''], [$web->id, 'local_certificate', 'example.com'], [$web->id, 'local_certificate', 'www.example.com']],
            array_map(static fn (CheckResult $result): array => [$result->instanceId, $result->checkName, $result->subject], $results),
        );
        $this->assertSame([CheckOutcome::Pass, CheckOutcome::Pass], [$results[2]->outcome, $results[3]->outcome]);
        $this->assertSame(
            ['instance_id' => $web->id, 'check_name' => 'local_certificate', 'outcome' => 'pass', 'checked_at' => $at, 'subject' => 'www.example.com'],
            array_intersect_key($this->rows('monitoring_check_results')[3], array_flip(['instance_id', 'check_name', 'subject', 'outcome', 'checked_at'])),
        );

        $summary = $this->environment->monitoring->dailyJobSummary([$web->id, $plain->id]);
        $this->assertSame([$web->id], array_keys($summary->certificateResults));
        $this->assertSame(['example.com', 'www.example.com'], array_map(static fn (CheckResult $result): string => $result->subject, $summary->certificateResults[$web->id]));
        $this->assertSame([$plain->id, $web->id], array_keys($summary->diskSizeResults));
    }

    public function testRunsWithNoInstancesWithoutCallingTheApi(): void
    {
        $requests = count($this->environment->http->requests);

        $this->assertSame([], $this->environment->monitoring->runDailyJob(DailyJobTrigger::Scheduled));
        $this->assertCount($requests, $this->environment->http->requests);
        $this->assertSame('scheduled', $this->rows('monitoring_daily_job_runs')[0]['triggered_by']);
        $this->assertNotNull($this->rows('monitoring_daily_job_runs')[0]['finished_at']);
    }

    public function testRefusesWhileARunIsInProgress(): void
    {
        $startedAt = $this->environment->clock->now() - 899;
        $this->insertUnfinishedRun($startedAt);

        try {
            $this->environment->monitoring->runDailyJob(DailyJobTrigger::Manual);
            $this->fail('Expected DailyJobAlreadyRunning.');
        } catch (DailyJobAlreadyRunning $exception) {
            $this->assertSame('The daily job is already running, started at ' . gmdate('Y-m-d H:i', $startedAt) . ' UTC.', $exception->getMessage());
        }

        $this->assertCount(1, $this->rows('monitoring_daily_job_runs'));
    }

    public function testAFinishedRunDoesNotBlock(): void
    {
        $this->environment->monitoring->runDailyJob(DailyJobTrigger::Scheduled);
        $this->environment->monitoring->runDailyJob(DailyJobTrigger::Manual);

        $this->assertCount(2, $this->rows('monitoring_daily_job_runs'));
    }

    public function testAnUnfinishedRunStopsBlockingAfterFifteenMinutes(): void
    {
        $this->insertUnfinishedRun($this->environment->clock->now() - 900);

        $this->environment->monitoring->runDailyJob(DailyJobTrigger::Manual);

        $this->assertCount(2, $this->rows('monitoring_daily_job_runs'));
    }

    public function testAnErrorDuringTheChecksMarksTheRunFailedWithoutResults(): void
    {
        $this->pick('web');
        // No listing queued: the fake HTTP client throws, as an unexpected
        // error would.

        try {
            $this->environment->monitoring->runDailyJob(DailyJobTrigger::Manual);
            $this->fail('Expected DailyJobFailed.');
        } catch (DailyJobFailed $exception) {
            $this->assertStringStartsWith('The daily job failed: RuntimeException: Unexpected request: GET https://compute.googleapis.com/', $exception->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
        }

        $run = $this->rows('monitoring_daily_job_runs')[0];
        $this->assertSame($this->environment->clock->now(), $run['finished_at']);
        $this->assertSame(1, $run['failed']);
        $this->assertSame([], $this->rows('monitoring_check_results'));
    }

    public function testAnErrorWhileStoringTheResultsMarksTheRunFailed(): void
    {
        $this->environment->database->pdo()->exec('DROP TABLE monitoring_check_results');
        $this->pick('web');
        $this->queueListing(['web' => 10]);

        try {
            $this->environment->monitoring->runDailyJob(DailyJobTrigger::Manual);
            $this->fail('Expected DailyJobFailed.');
        } catch (DailyJobFailed $exception) {
            $this->assertStringContainsString('monitoring_check_results', $exception->getMessage());
        }

        $this->assertSame(1, $this->rows('monitoring_daily_job_runs')[0]['failed']);
    }

    public function testAFailedRunDoesNotBlockTheNextOne(): void
    {
        $this->pick('web');

        try {
            $this->environment->monitoring->runDailyJob(DailyJobTrigger::Manual);
        } catch (DailyJobFailed) {
        }

        $this->queueListing(['web' => 10]);
        $this->environment->monitoring->runDailyJob(DailyJobTrigger::Manual);

        $this->assertSame([1, 0], array_column($this->rows('monitoring_daily_job_runs'), 'failed'));
    }
}
