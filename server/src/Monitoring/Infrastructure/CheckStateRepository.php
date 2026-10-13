<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Infrastructure;

use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Monitoring\Domain\CheckOutcome;
use Maguari\Server\Monitoring\Domain\CheckState;

final class CheckStateRepository
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    public function find(int $instanceId, string $checkName, string $subject): ?CheckState
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT instance_id, check_name, subject, outcome, since, detail FROM monitoring_check_states '
                . 'WHERE instance_id = ? AND check_name = ? AND subject = ?',
        );
        $statement->execute([$instanceId, $checkName, $subject]);
        $row = $statement->fetch();

        return $row === false ? null : self::state($row);
    }

    /**
     * @param string[] $checkNames
     * @return list<CheckState> in check name and subject order
     */
    public function forInstance(int $instanceId, array $checkNames): array
    {
        if ($checkNames === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($checkNames), '?'));
        $statement = $this->database->pdo()->prepare(
            'SELECT instance_id, check_name, subject, outcome, since, detail FROM monitoring_check_states '
                . "WHERE instance_id = ? AND check_name IN ({$placeholders}) ORDER BY check_name, subject",
        );
        $statement->execute([$instanceId, ...array_values($checkNames)]);

        return array_map(self::state(...), $statement->fetchAll());
    }

    /**
     * @return list<CheckState> every failing outcome, in instance, check name and subject order
     */
    public function failing(): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT instance_id, check_name, subject, outcome, since, detail FROM monitoring_check_states '
                . 'WHERE outcome = ? ORDER BY instance_id, check_name, subject',
        );
        $statement->execute([CheckOutcome::Fail->value]);

        return array_map(self::state(...), $statement->fetchAll());
    }

    public function save(CheckState $state): void
    {
        $this->database->pdo()->prepare(
            'INSERT INTO monitoring_check_states (instance_id, check_name, subject, outcome, since, detail) VALUES (?, ?, ?, ?, ?, ?) '
                . 'ON CONFLICT (instance_id, check_name, subject) DO UPDATE SET outcome = excluded.outcome, since = excluded.since, detail = excluded.detail',
        )->execute([$state->instanceId, $state->checkName, $state->subject, $state->outcome->value, $state->since, $state->detail]);
    }

    /**
     * @return bool whether there was a state to delete
     */
    public function delete(int $instanceId, string $checkName, string $subject): bool
    {
        $statement = $this->database->pdo()->prepare(
            'DELETE FROM monitoring_check_states WHERE instance_id = ? AND check_name = ? AND subject = ?',
        );
        $statement->execute([$instanceId, $checkName, $subject]);

        return $statement->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function state(array $row): CheckState
    {
        return new CheckState(
            (int) $row['instance_id'],
            $row['check_name'],
            $row['subject'],
            CheckOutcome::from($row['outcome']),
            (int) $row['since'],
            $row['detail'],
        );
    }
}
