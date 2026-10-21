<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Infrastructure;

use Maguari\Server\Kernel\Database\Database;

final class FailureStreakRepository
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    /**
     * @return int the failures in a row, this one included
     */
    public function increment(int $instanceId, string $checkName, string $subject): int
    {
        $this->database->pdo()->prepare(
            'INSERT INTO monitoring_check_failure_streaks (instance_id, check_name, subject, failures) VALUES (?, ?, ?, 1) '
                . 'ON CONFLICT (instance_id, check_name, subject) DO UPDATE SET failures = failures + 1',
        )->execute([$instanceId, $checkName, $subject]);

        return $this->failures($instanceId, $checkName, $subject);
    }

    public function failures(int $instanceId, string $checkName, string $subject): int
    {
        $statement = $this->database->pdo()->prepare('SELECT failures FROM monitoring_check_failure_streaks WHERE instance_id = ? AND check_name = ? AND subject = ?');
        $statement->execute([$instanceId, $checkName, $subject]);

        return (int) $statement->fetchColumn();
    }

    public function delete(int $instanceId, string $checkName, string $subject): void
    {
        $this->database->pdo()->prepare('DELETE FROM monitoring_check_failure_streaks WHERE instance_id = ? AND check_name = ? AND subject = ?')
            ->execute([$instanceId, $checkName, $subject]);
    }
}
