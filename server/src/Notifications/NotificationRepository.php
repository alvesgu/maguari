<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

use Maguari\Server\Kernel\Database\Database;

final class NotificationRepository
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    /**
     * @return ?int the new notification's ID, null when the incident already had one
     */
    public function add(int $incidentId, int $instanceId, string $checkName, string $subject, int $openedAt, string $detail): ?int
    {
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO notifications_notifications (incident_id, instance_id, check_name, subject, opened_at, detail) VALUES (?, ?, ?, ?, ?, ?) '
                . 'ON CONFLICT (incident_id) DO NOTHING',
        );
        $statement->execute([$incidentId, $instanceId, $checkName, $subject, $openedAt, $detail]);

        return $statement->rowCount() === 1 ? (int) $this->database->pdo()->lastInsertId() : null;
    }

    /**
     * @return list<Notification> every unresolved notification, oldest first,
     *         read without an administrator (hidden is false)
     */
    public function unresolved(): array
    {
        return $this->select(0, 'WHERE n.resolved_at IS NULL ORDER BY n.id', []);
    }

    public function find(int $id): ?Notification
    {
        return $this->select(0, 'WHERE n.id = ?', [$id])[0] ?? null;
    }

    /**
     * @return list<int> the incidents that have a notification, among $incidentIds
     */
    public function incidentsWithNotification(array $incidentIds): array
    {
        if ($incidentIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($incidentIds), '?'));
        $statement = $this->database->pdo()->prepare("SELECT incident_id FROM notifications_notifications WHERE incident_id IN ({$placeholders})");
        $statement->execute(array_values($incidentIds));

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function lastReconciledAt(): ?int
    {
        $ranAt = $this->database->pdo()->query('SELECT MAX(ran_at) FROM notifications_reconciliations')->fetchColumn();

        return $ranAt === null || $ranAt === false ? null : (int) $ranAt;
    }

    public function recordReconciliation(int $at, int $created, int $resolved): void
    {
        $this->database->pdo()->prepare('INSERT INTO notifications_reconciliations (ran_at, created, resolved) VALUES (?, ?, ?)')
            ->execute([$at, $created, $resolved]);
    }

    /**
     * @param ?string $resolution why, when Remediation said so
     * @return ?int the notification's ID, null when the incident had no
     *         unresolved notification
     */
    public function resolve(int $incidentId, int $resolvedAt, ?string $resolution): ?int
    {
        $statement = $this->database->pdo()->prepare(
            'UPDATE notifications_notifications SET resolved_at = ?, resolution = ? WHERE incident_id = ? AND resolved_at IS NULL',
        );
        $statement->execute([$resolvedAt, $resolution, $incidentId]);

        if ($statement->rowCount() !== 1) {
            return null;
        }

        $id = $this->database->pdo()->prepare('SELECT id FROM notifications_notifications WHERE incident_id = ?');
        $id->execute([$incidentId]);

        return (int) $id->fetchColumn();
    }

    /**
     * @param int[] $ids
     * @return array<int, Notification> by ID
     */
    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $notifications = [];

        foreach ($this->select(0, 'WHERE n.id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')', array_values($ids)) as $notification) {
            $notifications[$notification->id] = $notification;
        }

        return $notifications;
    }

    public function exists(int $id): bool
    {
        $statement = $this->database->pdo()->prepare('SELECT 1 FROM notifications_notifications WHERE id = ?');
        $statement->execute([$id]);

        return $statement->fetchColumn() !== false;
    }

    public function hide(int $id, int $administratorId, int $at): void
    {
        $this->database->pdo()->prepare(
            'INSERT INTO notifications_hidden (notification_id, administrator_id, hidden_at) VALUES (?, ?, ?) '
                . 'ON CONFLICT (notification_id, administrator_id) DO NOTHING',
        )->execute([$id, $administratorId, $at]);
    }

    /**
     * @return list<Notification> unresolved and not hidden by the administrator, newest first
     */
    public function visibleTo(int $administratorId): array
    {
        return $this->select(
            $administratorId,
            'WHERE n.resolved_at IS NULL AND h.notification_id IS NULL ORDER BY n.id DESC',
            [],
        );
    }

    /**
     * @return list<Notification> with id below $before (all when null), newest first
     */
    public function page(int $administratorId, ?int $before, int $limit): array
    {
        return $before === null
            ? $this->select($administratorId, 'ORDER BY n.id DESC LIMIT ?', [$limit])
            : $this->select($administratorId, 'WHERE n.id < ? ORDER BY n.id DESC LIMIT ?', [$before, $limit]);
    }

    /**
     * @return list<int> IDs at or above $from, oldest first
     */
    public function idsFrom(int $from, int $limit): array
    {
        $statement = $this->database->pdo()->prepare('SELECT id FROM notifications_notifications WHERE id >= ? ORDER BY id LIMIT ?');
        $statement->bindValue(1, $from, \PDO::PARAM_INT);
        $statement->bindValue(2, $limit, \PDO::PARAM_INT);
        $statement->execute();

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param list<int> $parameters after the administrator's ID
     * @return list<Notification>
     */
    private function select(int $administratorId, string $rest, array $parameters): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT n.id, n.incident_id, n.instance_id, n.check_name, n.subject, n.opened_at, n.resolved_at, n.detail, n.resolution, '
                . 'h.notification_id IS NOT NULL AS hidden FROM notifications_notifications AS n '
                . 'LEFT JOIN notifications_hidden AS h ON h.notification_id = n.id AND h.administrator_id = ? ' . $rest,
        );

        foreach ([$administratorId, ...$parameters] as $i => $value) {
            $statement->bindValue($i + 1, $value, \PDO::PARAM_INT);
        }

        $statement->execute();

        return array_map(
            static fn (array $row): Notification => new Notification(
                (int) $row['id'],
                (int) $row['incident_id'],
                (int) $row['instance_id'],
                $row['check_name'],
                $row['subject'],
                (int) $row['opened_at'],
                $row['resolved_at'] === null ? null : (int) $row['resolved_at'],
                (bool) $row['hidden'],
                $row['detail'],
                $row['resolution'],
            ),
            $statement->fetchAll(),
        );
    }
}
