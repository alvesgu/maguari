<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring;

use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Monitoring\Exception\InvalidReadings;
use Maguari\Shared\Protocol;

/**
 * The Monitoring context's public interface: readings stored as runs, and
 * later checks. Instances are referred to by their Fleet ID. Monitoring does
 * not know where readings come from.
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

    public function __construct(
        private readonly Database $database,
    ) {
        $this->runs = new MetricRunRepository($database);
        $this->runRule = new RunRule(self::EXPECTED_INTERVAL_SECONDS);
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
