<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Infrastructure;

use Maguari\Server\Kernel\Database\Database;

/**
 * Last heartbeat times (monitoring_heartbeats) and the tick's own times
 * (monitoring_ticks), for the heartbeat-age check.
 */
final class HeartbeatRepository
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    public function record(int $instanceId, int $at): void
    {
        $this->database->pdo()->prepare(
            'INSERT INTO monitoring_heartbeats (instance_id, last_at) VALUES (?, ?) '
                . 'ON CONFLICT (instance_id) DO UPDATE SET last_at = excluded.last_at',
        )->execute([$instanceId, $at]);
    }

    /**
     * @param int[] $instanceIds
     * @return array<int, int> last heartbeat time by instance ID, for those of
     *         $instanceIds that sent one, in ID order
     */
    public function lastAmong(array $instanceIds): array
    {
        if ($instanceIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($instanceIds), '?'));
        $statement = $this->database->pdo()->prepare(
            "SELECT instance_id, last_at FROM monitoring_heartbeats WHERE instance_id IN ({$placeholders}) ORDER BY instance_id",
        );
        $statement->execute(array_values($instanceIds));
        $lastAt = [];

        foreach ($statement->fetchAll() as $row) {
            $lastAt[(int) $row['instance_id']] = (int) $row['last_at'];
        }

        return $lastAt;
    }

    /**
     * @return ?array{last_at: int, resumed_at: int} null before the first tick
     */
    public function lastTick(): ?array
    {
        $row = $this->database->pdo()->query('SELECT last_at, resumed_at FROM monitoring_ticks WHERE id = 1')->fetch();

        return $row === false ? null : ['last_at' => (int) $row['last_at'], 'resumed_at' => (int) $row['resumed_at']];
    }

    public function recordTick(int $at, int $resumedAt): void
    {
        $this->database->pdo()->prepare(
            'INSERT INTO monitoring_ticks (id, last_at, resumed_at) VALUES (1, ?, ?) '
                . 'ON CONFLICT (id) DO UPDATE SET last_at = excluded.last_at, resumed_at = excluded.resumed_at',
        )->execute([$at, $resumedAt]);
    }
}
