<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Infrastructure;

use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Monitoring\Domain\ClientCheck;

final class ClientCheckRepository
{
    private const COLUMNS = 'id, instance_id, type, target, host_name, added_at';

    public function __construct(
        private readonly Database $database,
    ) {
    }

    /**
     * @return list<ClientCheck> in type, target and host name order
     */
    public function forInstance(int $instanceId): array
    {
        $statement = $this->database->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM monitoring_client_checks WHERE instance_id = ? ORDER BY type, target, host_name');
        $statement->execute([$instanceId]);

        return array_map(self::check(...), $statement->fetchAll());
    }

    public function find(int $instanceId, int $checkId): ?ClientCheck
    {
        $statement = $this->database->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM monitoring_client_checks WHERE id = ? AND instance_id = ?');
        $statement->execute([$checkId, $instanceId]);
        $row = $statement->fetch();

        return $row === false ? null : self::check($row);
    }

    /**
     * @return int the new row's ID
     */
    public function add(int $instanceId, string $type, string $target, string $hostName, int $at): int
    {
        $this->database->pdo()->prepare('INSERT INTO monitoring_client_checks (instance_id, type, target, host_name, added_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$instanceId, $type, $target, $hostName, $at]);

        return (int) $this->database->pdo()->lastInsertId();
    }

    public function remove(int $instanceId, int $checkId): void
    {
        $this->database->pdo()->prepare('DELETE FROM monitoring_client_checks WHERE id = ? AND instance_id = ?')->execute([$checkId, $instanceId]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function check(array $row): ClientCheck
    {
        return new ClientCheck((int) $row['id'], (int) $row['instance_id'], $row['type'], $row['target'], $row['host_name'], (int) $row['added_at']);
    }
}
