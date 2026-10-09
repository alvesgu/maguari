<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Monitoring;

use Maguari\Server\Monitoring\MonitoringApi;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

final class MonitoringApiTest extends TestCase
{
    private const AT = 1_790_000_000;

    private TestEnvironment $environment;
    private MonitoringApi $monitoring;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
        $this->monitoring = $this->environment->monitoring;
    }

    protected function tearDown(): void
    {
        $this->environment->cleanUp();
    }

    /**
     * @param array<string, int> $values by metric
     */
    private function record(int $instanceId, int $at, array $values): void
    {
        $items = [];

        foreach ($values as $metric => $value) {
            $items[] = ['metric' => $metric, 'value' => $value];
        }

        $this->monitoring->recordReadings($instanceId, $at, $this->monitoring->parseReadings($items));
    }

    /**
     * @return list<array{int, string, int, int, int}> instance ID, metric, value, start and end
     */
    private function runs(): array
    {
        return array_map(
            fn (array $row): array => array_values($row),
            $this->environment->database->pdo()->query(
                'SELECT instance_id, metric, value, start_at, end_at FROM monitoring_metric_runs ORDER BY id',
            )->fetchAll(),
        );
    }

    public function testRunsReadsOneMetricWithTheGapRule(): void
    {
        $this->record(7, self::AT, ['disk_used_bytes:/' => 100, 'disk_total_bytes:/' => 1_000_000]);
        $this->record(7, self::AT + 60, ['disk_used_bytes:/' => 100, 'disk_total_bytes:/' => 1_000_000]);
        // An outage: more than 90 seconds later, the same value starts a new run.
        $this->record(7, self::AT + 151, ['disk_used_bytes:/' => 100, 'disk_total_bytes:/' => 1_000_000]);

        $series = $this->monitoring->runs(7, ['metric' => ['disk_total_bytes:/'], 'from' => [(string) self::AT], 'to' => [(string) (self::AT + 3600)]]);

        $this->assertSame('disk_total_bytes:/', $series->query->metric);
        $this->assertSame(90, $series->maxGapSeconds);
        $this->assertFalse($series->truncated);
        $this->assertSame(
            [[self::AT, self::AT + 60, 1_000_000], [self::AT + 151, self::AT + 151, 1_000_000]],
            array_map(static fn ($run): array => [$run->startAt, $run->endAt, $run->value], $series->runs),
        );
    }

    public function testRunsDefaultsToTheLast24HoursEndingNow(): void
    {
        $series = $this->monitoring->runs(7, ['metric' => ['disk_used_bytes:/']]);

        $this->assertSame($this->environment->clock->now(), $series->query->to);
        $this->assertSame($this->environment->clock->now() - 86_400, $series->query->from);
    }

    public function testRunsKeepsTheNewest5000(): void
    {
        $pdo = $this->environment->database->pdo();
        $insert = $pdo->prepare('INSERT INTO monitoring_metric_runs (instance_id, metric, value, start_at, end_at) VALUES (7, ?, ?, ?, ?)');
        $pdo->beginTransaction();

        for ($i = 0; $i <= MonitoringApi::MAX_RUNS; $i++) {
            $insert->execute(['disk_used_bytes:/', $i, self::AT + 2 * $i, self::AT + 2 * $i]);
        }

        // Exactly the limit is not truncated.
        for ($i = 0; $i < MonitoringApi::MAX_RUNS; $i++) {
            $insert->execute(['disk_used_bytes:/boot', $i, self::AT + 2 * $i, self::AT + 2 * $i]);
        }

        $pdo->commit();
        $range = ['from' => [(string) self::AT], 'to' => [(string) (self::AT + 86_400)]];

        $series = $this->monitoring->runs(7, ['metric' => ['disk_used_bytes:/']] + $range);
        $this->assertSame(5_000, MonitoringApi::MAX_RUNS);
        $this->assertTrue($series->truncated);
        $this->assertCount(MonitoringApi::MAX_RUNS, $series->runs);
        $this->assertSame(1, $series->runs[0]->value);
        $this->assertSame(MonitoringApi::MAX_RUNS, $series->runs[MonitoringApi::MAX_RUNS - 1]->value);

        $series = $this->monitoring->runs(7, ['metric' => ['disk_used_bytes:/boot']] + $range);
        $this->assertFalse($series->truncated);
        $this->assertCount(MonitoringApi::MAX_RUNS, $series->runs);
        $this->assertSame(0, $series->runs[0]->value);
    }

    public function testTheExpectedIntervalIsTheHeartbeatInterval(): void
    {
        $this->assertSame(60, MonitoringApi::EXPECTED_INTERVAL_SECONDS);
    }

    public function testStoresReadingsAsRuns(): void
    {
        $this->record(7, self::AT, ['disk_total_bytes:/' => 10_000_000, 'disk_used_bytes:/' => 5_000_000]);
        $this->record(7, self::AT + 60, ['disk_total_bytes:/' => 10_000_000, 'disk_used_bytes:/' => 5_010_000]);
        $this->record(7, self::AT + 120, ['disk_total_bytes:/' => 10_000_000, 'disk_used_bytes:/' => 5_010_001]);

        $this->assertSame([
            [7, 'disk_total_bytes:/', 10_000_000, self::AT, self::AT + 120],
            // Within 0.1% of the total (10,000 bytes) of the run's first value,
            // then 10,001 bytes away from it, although only 1 from the last reading.
            [7, 'disk_used_bytes:/', 5_000_000, self::AT, self::AT + 60],
            [7, 'disk_used_bytes:/', 5_010_001, self::AT + 120, self::AT + 120],
        ], $this->runs());
    }

    public function testDiskTotalNeedsExactEquality(): void
    {
        $this->record(7, self::AT, ['disk_total_bytes:/' => 10_000_000]);
        $this->record(7, self::AT + 60, ['disk_total_bytes:/' => 10_000_001]);

        $this->assertCount(2, $this->runs());
    }

    public function testACertificateExpiryRunLastsUntilRenewal(): void
    {
        $this->record(7, self::AT, ['certificate_expires_at:example.com' => 1_797_000_000]);
        $this->record(7, self::AT + 60, ['certificate_expires_at:example.com' => 1_797_000_000]);
        $this->record(7, self::AT + 120, ['certificate_expires_at:example.com' => 1_802_000_000]);

        $this->assertSame([
            [7, 'certificate_expires_at:example.com', 1_797_000_000, self::AT, self::AT + 60],
            [7, 'certificate_expires_at:example.com', 1_802_000_000, self::AT + 120, self::AT + 120],
        ], $this->runs());
    }

    public function testAGapStartsANewRun(): void
    {
        $this->record(7, self::AT, ['disk_total_bytes:/' => 10]);
        $this->record(7, self::AT + 90, ['disk_total_bytes:/' => 10]);
        $this->record(7, self::AT + 181, ['disk_total_bytes:/' => 10]);

        $this->assertSame([
            [7, 'disk_total_bytes:/', 10, self::AT, self::AT + 90],
            [7, 'disk_total_bytes:/', 10, self::AT + 181, self::AT + 181],
        ], $this->runs());
    }

    public function testRunsAreSeparatePerInstanceAndPerMetric(): void
    {
        $this->record(7, self::AT, ['disk_total_bytes:/' => 10, 'disk_total_bytes:/boot' => 10]);
        $this->record(8, self::AT, ['disk_total_bytes:/' => 10]);
        $this->record(7, self::AT + 60, ['disk_total_bytes:/' => 10, 'disk_total_bytes:/boot' => 10]);
        $this->record(8, self::AT + 60, ['disk_total_bytes:/' => 20]);

        $this->assertSame([
            [7, 'disk_total_bytes:/', 10, self::AT, self::AT + 60],
            [7, 'disk_total_bytes:/boot', 10, self::AT, self::AT + 60],
            [8, 'disk_total_bytes:/', 10, self::AT, self::AT],
            [8, 'disk_total_bytes:/', 20, self::AT + 60, self::AT + 60],
        ], $this->runs());
    }

    public function testTheCurrentRunIsTheLatestStartThenTheHighestId(): void
    {
        // Two runs starting in the same second: the second one is current.
        $this->record(7, self::AT, ['disk_total_bytes:/' => 10]);
        $this->record(7, self::AT, ['disk_total_bytes:/' => 20]);
        $this->record(7, self::AT + 60, ['disk_total_bytes:/' => 20]);

        $this->assertSame([
            [7, 'disk_total_bytes:/', 10, self::AT, self::AT],
            [7, 'disk_total_bytes:/', 20, self::AT, self::AT + 60],
        ], $this->runs());
    }

    public function testAReadingFromBeforeTheCurrentRunsEndIsIgnored(): void
    {
        $this->record(7, self::AT, ['disk_total_bytes:/' => 10]);
        $this->record(7, self::AT + 60, ['disk_total_bytes:/' => 10]);
        $this->record(7, self::AT + 30, ['disk_total_bytes:/' => 20]);

        $this->assertSame([[7, 'disk_total_bytes:/', 10, self::AT, self::AT + 60]], $this->runs());
    }

    public function testNoReadingsWriteNothing(): void
    {
        $this->record(7, self::AT, []);

        $this->assertSame([], $this->runs());
    }
}
