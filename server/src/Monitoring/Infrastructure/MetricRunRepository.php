<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Infrastructure;

use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Monitoring\Domain\MetricRun;
use Maguari\Shared\Metric;

final class MetricRunRepository
{
    /** Public only so a test can check its query plan. */
    public const OVERLAPPING_SQL = 'SELECT id, value, start_at, end_at FROM monitoring_metric_runs '
        . 'WHERE instance_id = :instance AND metric = :metric AND start_at <= :to AND end_at >= :from '
        . 'AND start_at >= COALESCE((SELECT MAX(start_at) FROM monitoring_metric_runs '
        . 'WHERE instance_id = :instance AND metric = :metric AND start_at <= :from), 0) '
        . 'ORDER BY start_at DESC, id DESC LIMIT :limit';

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

        return self::run($row);
    }

    /**
     * The current run of every metric of one kind, by subject, in subject
     * order. The kind is matched with substr(), not LIKE, because kinds
     * contain "_", which LIKE treats as a wildcard.
     *
     * @return array<string, MetricRun>
     */
    public function currentOfKind(int $instanceId, string $kind): array
    {
        $prefix = $kind . Metric::SEPARATOR;
        $statement = $this->database->pdo()->prepare(
            'SELECT id, metric, value, start_at, end_at FROM monitoring_metric_runs AS run '
                . 'WHERE instance_id = ? AND substr(metric, 1, ?) = ? AND id = ('
                . 'SELECT id FROM monitoring_metric_runs WHERE instance_id = run.instance_id AND metric = run.metric '
                . 'ORDER BY start_at DESC, id DESC LIMIT 1) '
                . 'ORDER BY metric',
        );
        $statement->execute([$instanceId, strlen($prefix), $prefix]);
        $runs = [];

        foreach ($statement->fetchAll() as $row) {
            $runs[substr($row['metric'], strlen($prefix))] = self::run($row);
        }

        return $runs;
    }

    /**
     * The newest $limit runs of one metric with start_at <= $to and
     * end_at >= $from, in start order (ties by ID). Runs never overlap, so
     * only the last run starting at or before $from can reach into the range
     * from before it: both conditions on start_at search the index, and a
     * long history outside the range is never read.
     *
     * @return list<MetricRun>
     */
    public function overlapping(int $instanceId, string $metric, int $from, int $to, int $limit): array
    {
        $statement = $this->database->pdo()->prepare(self::OVERLAPPING_SQL);
        $statement->bindValue('instance', $instanceId, \PDO::PARAM_INT);
        $statement->bindValue('metric', $metric);
        $statement->bindValue('from', $from, \PDO::PARAM_INT);
        $statement->bindValue('to', $to, \PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, \PDO::PARAM_INT);
        $statement->execute();

        return array_reverse(array_map(self::run(...), $statement->fetchAll()));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function run(array $row): MetricRun
    {
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
