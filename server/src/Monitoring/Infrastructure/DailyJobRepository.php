<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Infrastructure;

use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Monitoring\Domain\CheckOutcome;
use Maguari\Server\Monitoring\Domain\CheckResult;
use Maguari\Server\Monitoring\Domain\DailyJobRun;
use Maguari\Server\Monitoring\Domain\DailyJobState;
use Maguari\Server\Monitoring\Domain\DailyJobTrigger;
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
            'INSERT INTO monitoring_check_results (job_run_id, instance_id, check_name, subject, outcome, detail, checked_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
        )->execute([$runId, $result->instanceId, $result->checkName, $result->subject, $result->outcome->value, $result->detail, $result->checkedAt]);
    }

    public function finish(int $runId, int $at): void
    {
        $this->database->pdo()->prepare('UPDATE monitoring_daily_job_runs SET finished_at = ? WHERE id = ?')->execute([$at, $runId]);
    }

    /**
     * The latest run, optionally only a succeeded or a scheduled one.
     *
     * @param int $runningSince an unfinished run started at or before this was killed
     */
    public function latest(int $runningSince, bool $succeeded = false, ?DailyJobTrigger $trigger = null): ?DailyJobRun
    {
        $conditions = [];
        $parameters = [];

        if ($succeeded) {
            $conditions[] = 'finished_at IS NOT NULL AND failed = 0';
        }

        if ($trigger !== null) {
            $conditions[] = 'triggered_by = ?';
            $parameters[] = $trigger->value;
        }

        $statement = $this->database->pdo()->prepare(
            'SELECT id, triggered_by, started_at, finished_at, failed FROM monitoring_daily_job_runs'
                . ($conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions))
                . ' ORDER BY started_at DESC, id DESC LIMIT 1',
        );
        $statement->execute($parameters);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        $startedAt = (int) $row['started_at'];
        $finishedAt = $row['finished_at'] === null ? null : (int) $row['finished_at'];
        $state = match (true) {
            (int) $row['failed'] === 1 => DailyJobState::Failed,
            $finishedAt !== null => DailyJobState::Succeeded,
            $startedAt > $runningSince => DailyJobState::Running,
            default => DailyJobState::Killed,
        };

        return new DailyJobRun((int) $row['id'], DailyJobTrigger::from($row['triggered_by']), $startedAt, $finishedAt, $state);
    }

    /**
     * A check's results in a run, in the order they were stored, which is
     * subject order for checks with several results per instance.
     *
     * @param int[] $instanceIds
     * @return array<int, list<CheckResult>> by instance ID; instances without a result are left out
     */
    public function results(int $runId, string $checkName, array $instanceIds): array
    {
        if ($instanceIds === []) {
            return [];
        }

        $statement = $this->database->pdo()->prepare(
            'SELECT instance_id, check_name, subject, outcome, detail, checked_at FROM monitoring_check_results '
                . 'WHERE job_run_id = ? AND check_name = ? AND instance_id IN (' . implode(', ', array_fill(0, count($instanceIds), '?')) . ') '
                . 'ORDER BY id',
        );
        $statement->execute([$runId, $checkName, ...array_values($instanceIds)]);
        $results = [];

        foreach ($statement->fetchAll() as $row) {
            $results[(int) $row['instance_id']][] = new CheckResult(
                (int) $row['instance_id'],
                $row['check_name'],
                CheckOutcome::from($row['outcome']),
                $row['detail'],
                (int) $row['checked_at'],
                $row['subject'],
            );
        }

        return $results;
    }

    public function fail(int $runId, int $at): void
    {
        $this->database->pdo()->prepare('UPDATE monitoring_daily_job_runs SET finished_at = ?, failed = 1 WHERE id = ?')
            ->execute([$at, $runId]);
    }
}
