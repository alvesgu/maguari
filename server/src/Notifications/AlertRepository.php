<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

use Maguari\Server\Kernel\Database\Database;

final class AlertRepository
{
    private const COLUMNS = 'id, notification_id, kind, created_at, sent_at, given_up_at, attempts, last_failure, summary_id';

    public function __construct(
        private readonly Database $database,
    ) {
    }

    /**
     * Adds a pending alert, once per notification and kind. Call inside a transaction.
     */
    public function add(int $notificationId, AlertKind $kind, int $at): void
    {
        $this->database->pdo()->prepare(
            'INSERT INTO notifications_alerts (notification_id, kind, created_at) VALUES (?, ?, ?) '
                . 'ON CONFLICT (notification_id, kind) DO NOTHING',
        )->execute([$notificationId, $kind->value, $at]);
    }

    /**
     * @return list<Alert> oldest first
     */
    public function pending(): array
    {
        return $this->select('WHERE sent_at IS NULL AND given_up_at IS NULL ORDER BY id', []);
    }

    /**
     * @param int[] $notificationIds
     * @return array<int, list<Alert>> by notification ID, opened before resolved
     */
    public function forNotifications(array $notificationIds): array
    {
        if ($notificationIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($notificationIds), '?'));
        $alerts = [];

        foreach ($this->select("WHERE notification_id IN ({$placeholders}) ORDER BY id", array_values($notificationIds)) as $alert) {
            $alerts[$alert->notificationId][] = $alert;
        }

        return $alerts;
    }

    /**
     * Whether the notification's "opened" alert was sent or may still be.
     */
    public function openedNotGivenUp(int $notificationId): bool
    {
        $statement = $this->database->pdo()->prepare(
            "SELECT 1 FROM notifications_alerts WHERE notification_id = ? AND kind = 'opened' AND given_up_at IS NULL",
        );
        $statement->execute([$notificationId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param int[] $alertIds
     * @return int the summary's ID
     */
    public function summarize(AlertKind $kind, array $alertIds, int $at): int
    {
        $pdo = $this->database->pdo();
        $pdo->prepare('INSERT INTO notifications_alert_summaries (kind, created_at) VALUES (?, ?)')->execute([$kind->value, $at]);
        $summaryId = (int) $pdo->lastInsertId();
        $update = $pdo->prepare('UPDATE notifications_alerts SET summary_id = ? WHERE id = ?');

        foreach ($alertIds as $alertId) {
            $update->execute([$summaryId, $alertId]);
        }

        return $summaryId;
    }

    /**
     * @param int[] $alertIds
     */
    public function markSent(array $alertIds, int $at): void
    {
        $this->update('sent_at = ?, attempts = attempts + 1', [$at], $alertIds);
    }

    /**
     * @param int[] $alertIds
     */
    public function recordFailure(array $alertIds, string $sentence): void
    {
        $this->update('attempts = attempts + 1, last_failure = ?', [$sentence], $alertIds);
    }

    /**
     * @param int[] $alertIds
     * @param ?string $sentence null keeps the last failure's sentence
     */
    public function giveUp(array $alertIds, int $at, ?string $sentence): void
    {
        $this->update('given_up_at = ?, last_failure = COALESCE(?, last_failure)', [$at, $sentence], $alertIds);
    }

    /**
     * @param list<int|string|null> $values
     * @param int[] $alertIds
     */
    private function update(string $set, array $values, array $alertIds): void
    {
        $statement = $this->database->pdo()->prepare("UPDATE notifications_alerts SET {$set} WHERE id = ?");

        foreach ($alertIds as $alertId) {
            $statement->execute([...$values, $alertId]);
        }
    }

    /**
     * @param list<int> $parameters
     * @return list<Alert>
     */
    private function select(string $rest, array $parameters): array
    {
        $statement = $this->database->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM notifications_alerts ' . $rest);
        $statement->execute($parameters);

        return array_map(
            static fn (array $row): Alert => new Alert(
                (int) $row['id'],
                (int) $row['notification_id'],
                AlertKind::from($row['kind']),
                (int) $row['created_at'],
                $row['sent_at'] === null ? null : (int) $row['sent_at'],
                $row['given_up_at'] === null ? null : (int) $row['given_up_at'],
                (int) $row['attempts'],
                $row['last_failure'],
                $row['summary_id'] === null ? null : (int) $row['summary_id'],
            ),
            $statement->fetchAll(),
        );
    }
}
