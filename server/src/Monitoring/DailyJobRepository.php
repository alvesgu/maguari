<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring;

use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Monitoring\Exception\DailyJobAlreadyRunning;

final class DailyJobRepository
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    /**
     * Starts a run unless one started after $runningSince is still
     * unfinished. Call inside a transaction.
     *
     * @return int the new run's ID
     * @throws DailyJobAlreadyRunning
     */
    public function start(DailyJobTrigger $trigger, int $at, int $runningSince): int
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT started_at FROM monitoring_daily_job_runs WHERE finished_at IS NULL AND started_at > ? '
                . 'ORDER BY started_at DESC LIMIT 1',
        );
        $statement->execute([$runningSince]);
        $startedAt = $statement->fetchColumn();

        if ($startedAt !== false) {
            throw new DailyJobAlreadyRunning((int) $startedAt);
        }

        $this->database->pdo()->prepare('INSERT INTO monitoring_daily_job_runs (triggered_by, started_at) VALUES (?, ?)')
            ->execute([$trigger->value, $at]);

        return (int) $this->database->pdo()->lastInsertId();
    }

    public function addResult(int $runId, CheckResult $result): void
    {
        $this->database->pdo()->prepare(
            'INSERT INTO monitoring_check_results (job_run_id, instance_id, check_name, outcome, detail, checked_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?)',
        )->execute([$runId, $result->instanceId, $result->checkName, $result->outcome->value, $result->detail, $result->checkedAt]);
    }

    public function finish(int $runId, int $at): void
    {
        $this->database->pdo()->prepare('UPDATE monitoring_daily_job_runs SET finished_at = ? WHERE id = ?')->execute([$at, $runId]);
    }

    public function fail(int $runId, int $at): void
    {
        $this->database->pdo()->prepare('UPDATE monitoring_daily_job_runs SET finished_at = ?, failed = 1 WHERE id = ?')
            ->execute([$at, $runId]);
    }
}
