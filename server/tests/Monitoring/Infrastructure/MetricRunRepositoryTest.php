<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Monitoring\Infrastructure;

use Maguari\Server\Monitoring\Domain\MetricRun;
use Maguari\Server\Monitoring\Infrastructure\MetricRunRepository;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Reading runs back for charts (design section 9.1).
 */
final class MetricRunRepositoryTest extends TestCase
{
    private const FROM = 1_790_000_000;
    private const TO = 1_790_003_600;

    private TestEnvironment $environment;
    private MetricRunRepository $runs;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
        $this->runs = new MetricRunRepository($this->environment->database);
    }

    protected function tearDown(): void
    {
        $this->environment->cleanUp();
    }

    private function insert(int $startAt, int $endAt, int $value = 1, string $metric = 'disk_used_bytes:/', int $instanceId = 1): void
    {
        $this->environment->database->pdo()->prepare(
            'INSERT INTO monitoring_metric_runs (instance_id, metric, value, start_at, end_at) VALUES (?, ?, ?, ?, ?)',
        )->execute([$instanceId, $metric, $value, $startAt, $endAt]);
    }

    /**
     * @return list<array{int, int, int|float}> start, end and value
     */
    private function overlapping(int $limit = 100, string $metric = 'disk_used_bytes:/'): array
    {
        return array_map(
            static fn (MetricRun $run): array => [$run->startAt, $run->endAt, $run->value],
            $this->runs->overlapping(1, $metric, self::FROM, self::TO, $limit),
        );
    }

    public function testReturnsTheRunsThatOverlapTheRangeInStartOrder(): void
    {
        $this->insert(self::FROM - 600, self::FROM - 1, 1);   // ends before from
        $this->insert(self::FROM - 300, self::FROM, 2);       // ends exactly at from
        $this->insert(self::FROM + 60, self::FROM + 600, 3);  // inside
        $this->insert(self::FROM + 660, self::TO + 600, 4);   // starts inside, ends after to
        $this->insert(self::TO, self::TO + 1_200, 5);         // starts exactly at to
        $this->insert(self::TO + 1, self::TO + 1_800, 6);     // starts after to

        $this->assertSame([
            [self::FROM - 300, self::FROM, 2],
            [self::FROM + 60, self::FROM + 600, 3],
            [self::FROM + 660, self::TO + 600, 4],
            [self::TO, self::TO + 1_200, 5],
        ], $this->overlapping());
    }

    public function testARunCoveringTheWholeRange(): void
    {
        $this->insert(self::FROM - 86_400, self::TO + 86_400, 7);

        $this->assertSame([[self::FROM - 86_400, self::TO + 86_400, 7]], $this->overlapping());
    }

    public function testTiesAreInIdOrder(): void
    {
        $this->insert(self::FROM + 60, self::FROM + 60, 1);
        $this->insert(self::FROM + 60, self::FROM + 120, 2);

        $this->assertSame([[self::FROM + 60, self::FROM + 60, 1], [self::FROM + 60, self::FROM + 120, 2]], $this->overlapping());
    }

    public function testOnlyThatInstanceAndMetric(): void
    {
        $this->insert(self::FROM, self::TO, 1);
        $this->insert(self::FROM, self::TO, 2, 'disk_used_bytes:/boot');
        $this->insert(self::FROM, self::TO, 3, 'disk_used_bytes:');
        $this->insert(self::FROM, self::TO, 4, instanceId: 2);

        $this->assertSame([[self::FROM, self::TO, 1]], $this->overlapping());
        $this->assertSame([], $this->overlapping(metric: 'disk_used_bytes:/b'));
    }

    public function testTheLimitKeepsTheNewestRuns(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->insert(self::FROM + $i * 120, self::FROM + $i * 120 + 60, $i);
        }

        $this->assertSame([
            [self::FROM + 360, self::FROM + 420, 3],
            [self::FROM + 480, self::FROM + 540, 4],
        ], $this->overlapping(2));
    }

    /**
     * Both conditions on start_at search the series index, so a long
     * history outside the range is never read, and no sort is needed.
     */
    public function testTheQueryOnlySearchesTheIndex(): void
    {
        $statement = $this->environment->database->pdo()->prepare('EXPLAIN QUERY PLAN ' . MetricRunRepository::OVERLAPPING_SQL);
        $statement->execute(['instance' => 1, 'metric' => 'disk_used_bytes:/', 'from' => self::FROM, 'to' => self::TO, 'limit' => 10]);
        $steps = array_column($statement->fetchAll(), 'detail');
        $reads = array_values(array_filter($steps, static fn (string $step): bool => str_contains($step, 'monitoring_metric_runs')));

        $this->assertCount(2, $reads, implode("\n", $steps));

        foreach ($reads as $read) {
            $this->assertMatchesRegularExpression('/^SEARCH monitoring_metric_runs USING (COVERING )?INDEX monitoring_metric_runs_series /', $read);
        }

        foreach ($steps as $step) {
            $this->assertStringNotContainsString('TEMP B-TREE', $step);
        }
    }
}
