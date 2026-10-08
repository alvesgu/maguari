<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring;

use Maguari\Server\Fleet\FleetApi;
use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Monitoring\Application\DailyJob;
use Maguari\Server\Monitoring\Domain\CheckResult;
use Maguari\Server\Monitoring\Domain\DailyJobSummary;
use Maguari\Server\Monitoring\Domain\DailyJobTrigger;
use Maguari\Server\Monitoring\Domain\Readings;
use Maguari\Server\Monitoring\Domain\RunDecision;
use Maguari\Server\Monitoring\Domain\RunRule;
use Maguari\Server\Monitoring\Exception\DailyJobAlreadyRunning;
use Maguari\Server\Monitoring\Exception\DailyJobFailed;
use Maguari\Server\Monitoring\Exception\InvalidReadings;
use Maguari\Server\Monitoring\Infrastructure\MetricRunRepository;
use Maguari\Shared\Protocol;

/**
 * The Monitoring context's public interface: readings stored as runs, and the
 * daily job with its checks. Instances are referred to by their Fleet ID.
 * Monitoring does not know where readings come from. It asks Fleet for the
 * picked instances and their disks.
 */
final class MonitoringApi
{
    /**
     * How often readings are expected. Fixed at the default heartbeat
     * interval until the interval becomes configurable (design section 7.3).
     */
    public const EXPECTED_INTERVAL_SECONDS = Protocol::HEARTBEAT_INTERVAL_SECONDS;

    private readonly MetricRunRepository $runs;
    private readonly RunRule $runRule;
    private readonly DailyJob $dailyJob;

    public function __construct(
        private readonly Database $database,
        Clock $clock,
        FleetApi $fleet,
    ) {
        $this->runs = new MetricRunRepository($database);
        $this->runRule = new RunRule(self::EXPECTED_INTERVAL_SECONDS);
        $this->dailyJob = new DailyJob($database, $clock, $fleet, $this->runs);
    }

    /**
     * Runs the daily checks on every picked instance and stores the results
     * (design section 6.3). Calls the Compute Engine API, so it takes seconds.
     *
     * @return CheckResult[] by instance, in the order of FleetApi::pickedInstances():
     *         the disk size result, then one per certificate in domain order
     * @throws DailyJobAlreadyRunning
     * @throws DailyJobFailed after marking the run failed. The caller logs it.
     */
    public function runDailyJob(DailyJobTrigger $trigger): array
    {
        return $this->dailyJob->run($trigger);
    }

    /**
     * The daily job's latest runs and the given instances' latest disk size
     * and certificate results, for the dashboard. Reads only SQLite.
     *
     * @param int[] $instanceIds
     */
    public function dailyJobSummary(array $instanceIds): DailyJobSummary
    {
        return $this->dailyJob->summary($instanceIds);
    }

    /**
     * Validates a heartbeat's readings without writing anything, so the
     * caller can reject the whole heartbeat first.
     *
     * @param list<mixed> $readings as decoded from JSON
     * @throws InvalidReadings
     */
    public function parseReadings(array $readings): Readings
    {
        return Readings::parse($readings);
    }

    /**
     * Applies the run rule (design section 9.1) to each reading, all in one
     * transaction, so concurrent heartbeats of one instance cannot both
     * extend or both insert.
     *
     * @param int $at the server's receive time
     */
    public function recordReadings(int $instanceId, int $at, Readings $readings): void
    {
        if ($readings->readings === []) {
            return;
        }

        $this->database->transaction(function () use ($instanceId, $at, $readings): void {
            foreach ($readings->readings as $reading) {
                $current = $this->runs->current($instanceId, $reading->metric);

                match ($this->runRule->decide($current, $reading, $at)) {
                    RunDecision::Insert => $this->runs->insert($instanceId, $reading->metric, $reading->value, $at),
                    RunDecision::Extend => $this->runs->extend($current->id, $at),
                    RunDecision::Ignore => null,
                };
            }
        });
    }
}
