<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Application;

use Maguari\Server\Fleet\FleetApi;
use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Monitoring\Domain\CheckResult;
use Maguari\Server\Monitoring\Domain\HeartbeatAgeRule;
use Maguari\Server\Monitoring\Domain\HeartbeatStatus;
use Maguari\Server\Monitoring\Infrastructure\HeartbeatRepository;
use Maguari\Server\Monitoring\MonitoringApi;

/**
 * The heartbeat-age check (design section 6.2), judged by the tick for every
 * picked instance that has sent a heartbeat, and the heartbeat states pages
 * show.
 */
final class HeartbeatAges
{
    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
        private readonly FleetApi $fleet,
        private readonly HeartbeatRepository $heartbeats,
        private readonly CheckStates $checkStates,
    ) {
    }

    /**
     * Judges every instance in one transaction, together with the outcome
     * changes and their events.
     *
     * @return list<CheckResult> in instance ID order
     */
    public function judge(): array
    {
        $instanceIds = array_map(static fn ($instance): int => $instance->id, $this->fleet->pickedInstances());
        $at = $this->clock->now();

        return $this->database->transaction(function () use ($instanceIds, $at): array {
            $previous = $this->heartbeats->lastTick();
            $resumedAt = HeartbeatAgeRule::ticksResumedAt($previous['last_at'] ?? null, $previous['resumed_at'] ?? null, $at);
            $this->heartbeats->recordTick($at, $resumedAt);
            $results = [];

            foreach ($this->heartbeats->lastAmong($instanceIds) as $instanceId => $lastAt) {
                $result = HeartbeatAgeRule::check($instanceId, $lastAt, self::intervalSeconds($instanceId), $resumedAt, $at);
                $this->checkStates->record($result);
                $results[] = $result;
            }

            return $results;
        });
    }

    /**
     * @param int[] $instanceIds
     * @return array<int, HeartbeatStatus> one per ID, "No heartbeat yet" for
     *         instances that never sent one
     */
    public function statuses(array $instanceIds): array
    {
        $lastAt = $this->heartbeats->lastAmong($instanceIds);
        $at = $this->clock->now();
        $statuses = [];

        foreach ($instanceIds as $instanceId) {
            $statuses[$instanceId] = HeartbeatAgeRule::status($lastAt[$instanceId] ?? null, self::intervalSeconds($instanceId), $at);
        }

        return $statuses;
    }

    /**
     * The instance's heartbeat interval. The same for every instance until
     * intervals are configurable (design sections 7.3 and 18 item 3).
     */
    public static function intervalSeconds(int $instanceId): int
    {
        return MonitoringApi::EXPECTED_INTERVAL_SECONDS;
    }
}
