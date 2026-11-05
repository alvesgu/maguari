<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Infrastructure;

use Maguari\Server\Kernel\Database\Database;

final class CheckUnitRepository
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    public static function key(string $checkName, string $subject): string
    {
        return $checkName . "\n" . $subject;
    }

    /**
     * @return array<string, string> units by key()
     */
    public function forInstance(int $instanceId): array
    {
        $statement = $this->database->pdo()->prepare('SELECT check_name, subject, unit FROM remediation_check_units WHERE instance_id = ?');
        $statement->execute([$instanceId]);
        $units = [];

        foreach ($statement->fetchAll() as $row) {
            $units[self::key($row['check_name'], $row['subject'])] = $row['unit'];
        }

        return $units;
    }

    public function save(int $instanceId, string $checkName, string $subject, string $unit): void
    {
        $this->database->pdo()->prepare(
            'INSERT INTO remediation_check_units (instance_id, check_name, subject, unit) VALUES (?, ?, ?, ?) '
                . 'ON CONFLICT (instance_id, check_name, subject) DO UPDATE SET unit = excluded.unit',
        )->execute([$instanceId, $checkName, $subject, $unit]);
    }

    public function delete(int $instanceId, string $checkName, string $subject): void
    {
        $this->database->pdo()->prepare('DELETE FROM remediation_check_units WHERE instance_id = ? AND check_name = ? AND subject = ?')
            ->execute([$instanceId, $checkName, $subject]);
    }
}
