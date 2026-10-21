<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Infrastructure;

use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Monitoring\Domain\HttpCheck;

final class HttpCheckRepository
{
    private const COLUMNS = 'id, instance_id, url, interval_seconds, added_at, next_due_at';

    public function __construct(
        private readonly Database $database,
    ) {
    }

    /**
     * @return list<HttpCheck> in URL order
     */
    public function forInstance(int $instanceId): array
    {
        $statement = $this->database->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM monitoring_http_checks WHERE instance_id = ? ORDER BY url');
        $statement->execute([$instanceId]);

        return array_map(self::check(...), $statement->fetchAll());
    }

    public function find(int $instanceId, int $checkId): ?HttpCheck
    {
        $statement = $this->database->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM monitoring_http_checks WHERE id = ? AND instance_id = ?');
        $statement->execute([$checkId, $instanceId]);
        $row = $statement->fetch();

        return $row === false ? null : self::check($row);
    }

    /**
     * @return list<HttpCheck> those due at $at, the longest overdue first
     */
    public function due(int $at): array
    {
        $statement = $this->database->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM monitoring_http_checks WHERE next_due_at <= ? ORDER BY next_due_at, id');
        $statement->execute([$at]);

        return array_map(self::check(...), $statement->fetchAll());
    }

    /**
     * Every check's interval, for the egress estimate.
     *
     * @return list<int>
     */
    public function intervals(): array
    {
        return array_map('intval', $this->database->pdo()->query('SELECT interval_seconds FROM monitoring_http_checks')->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @return int the new row's ID
     */
    public function add(int $instanceId, string $url, int $intervalSeconds, int $at): int
    {
        $this->database->pdo()->prepare('INSERT INTO monitoring_http_checks (instance_id, url, interval_seconds, added_at, next_due_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$instanceId, $url, $intervalSeconds, $at, $at]);

        return (int) $this->database->pdo()->lastInsertId();
    }

    public function changeInterval(int $checkId, int $intervalSeconds, int $nextDueAt): void
    {
        $this->database->pdo()->prepare('UPDATE monitoring_http_checks SET interval_seconds = ?, next_due_at = ? WHERE id = ?')
            ->execute([$intervalSeconds, $nextDueAt, $checkId]);
    }

    public function scheduleNext(int $checkId, int $nextDueAt): void
    {
        $this->database->pdo()->prepare('UPDATE monitoring_http_checks SET next_due_at = ? WHERE id = ?')->execute([$nextDueAt, $checkId]);
    }

    public function remove(int $instanceId, int $checkId): void
    {
        $this->database->pdo()->prepare('DELETE FROM monitoring_http_checks WHERE id = ? AND instance_id = ?')->execute([$checkId, $instanceId]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function check(array $row): HttpCheck
    {
        return new HttpCheck(
            (int) $row['id'],
            (int) $row['instance_id'],
            $row['url'],
            (int) $row['interval_seconds'],
            (int) $row['added_at'],
            (int) $row['next_due_at'],
        );
    }
}
