<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Infrastructure;

use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Monitoring\Domain\MetricRun;

final class MetricRunRepository
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    /**
     * The run with the latest start_at, ties broken by the highest ID.
     */
    public function current(int $instanceId, string $metric): ?MetricRun
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT id, value, start_at, end_at FROM monitoring_metric_runs WHERE instance_id = ? AND metric = ? '
                . 'ORDER BY start_at DESC, id DESC LIMIT 1',
        );
        $statement->execute([$instanceId, $metric]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        $value = $row['value'];

        return new MetricRun(
            (int) $row['id'],
            is_int($value) || is_float($value) ? $value : (int) $value,
            (int) $row['start_at'],
            (int) $row['end_at'],
        );
    }

    public function insert(int $instanceId, string $metric, int $value, int $at): void
    {
        $this->database->pdo()->prepare(
            'INSERT INTO monitoring_metric_runs (instance_id, metric, value, start_at, end_at) VALUES (?, ?, ?, ?, ?)',
        )->execute([$instanceId, $metric, $value, $at, $at]);
    }

    public function extend(int $runId, int $at): void
    {
        $this->database->pdo()->prepare('UPDATE monitoring_metric_runs SET end_at = ? WHERE id = ?')->execute([$at, $runId]);
    }
}
