<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Infrastructure;

use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Monitoring\Domain\CheckOutcome;
use Maguari\Server\Monitoring\Domain\CheckResult;

final class NotCheckedResultRepository
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    /**
     * Keeps $result, a "not checked" one, in place of the previous.
     */
    public function save(CheckResult $result): void
    {
        $this->database->pdo()->prepare(
            'INSERT INTO monitoring_check_not_checked (instance_id, check_name, subject, detail, checked_at) VALUES (?, ?, ?, ?, ?) '
                . 'ON CONFLICT (instance_id, check_name, subject) DO UPDATE SET detail = excluded.detail, checked_at = excluded.checked_at',
        )->execute([$result->instanceId, $result->checkName, $result->subject, $result->detail, $result->checkedAt]);
    }

    public function find(int $instanceId, string $checkName, string $subject): ?CheckResult
    {
        $statement = $this->database->pdo()->prepare('SELECT detail, checked_at FROM monitoring_check_not_checked WHERE instance_id = ? AND check_name = ? AND subject = ?');
        $statement->execute([$instanceId, $checkName, $subject]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : new CheckResult($instanceId, $checkName, CheckOutcome::NotChecked, (string) $row['detail'], (int) $row['checked_at'], $subject);
    }

    public function delete(int $instanceId, string $checkName, string $subject): void
    {
        $this->database->pdo()->prepare('DELETE FROM monitoring_check_not_checked WHERE instance_id = ? AND check_name = ? AND subject = ?')
            ->execute([$instanceId, $checkName, $subject]);
    }
}
