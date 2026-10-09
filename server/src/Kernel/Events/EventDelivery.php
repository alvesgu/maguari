<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\Events;

use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;

/**
 * Delivers pending events to their subscribers (design section 2.1 rule 5),
 * run only by the tick. Each event is delivered in its own transaction: every
 * subscriber that handles its type runs and the event is marked delivered,
 * so its effects happen exactly once. Events go in ID order, and delivery
 * stops at an event whose subscribers fail, so the order holds. An event that
 * failed on MAX_FAILED_ATTEMPTS ticks is skipped, so one bug cannot stop every
 * later event for good.
 *
 * Subscribers are given here, where the app is wired, so Kernel never names
 * a context.
 */
final class EventDelivery
{
    /** At most this many events per call, so a subscriber loop cannot run forever. */
    public const MAX_EVENTS_PER_DELIVERY = 1_000;

    public const MAX_FAILED_ATTEMPTS = 3;

    /** Delivered events are deleted this long after delivery. */
    public const KEEP_DELIVERED_SECONDS = 7 * 86_400;

    /**
     * @param list<Subscriber> $subscribers
     */
    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
        private readonly array $subscribers,
    ) {
    }

    /**
     * Delivers until nothing is pending, including the events subscribers
     * write meanwhile, up to $limit events.
     */
    public function deliver(int $limit = self::MAX_EVENTS_PER_DELIVERY): DeliveryReport
    {
        $delivered = [];
        $failures = [];

        while (count($delivered) + count($failures) < $limit) {
            $row = $this->nextPending();

            if ($row === null) {
                return new DeliveryReport($delivered, $failures, false);
            }

            $id = (int) $row['id'];
            $type = (string) $row['type'];

            try {
                $this->deliverOne($id, $type, (string) $row['payload'], (int) $row['occurred_at']);
                $delivered[] = new DeliveredEvent($id, $type);
            } catch (\Throwable $exception) {
                $attempts = $this->recordFailure($id);
                $failure = new FailedDelivery($id, $type, $exception, $attempts, $attempts >= self::MAX_FAILED_ATTEMPTS);
                $failures[] = $failure;

                if (!$failure->skipped) {
                    return new DeliveryReport($delivered, $failures, false);
                }
            }
        }

        return new DeliveryReport($delivered, $failures, $this->nextPending() !== null);
    }

    /**
     * Deletes events delivered more than KEEP_DELIVERED_SECONDS ago. Skipped
     * events are never delivered, so they stay for diagnosis.
     *
     * @return int how many were deleted
     */
    public function pruneDelivered(): int
    {
        $statement = $this->database->pdo()->prepare('DELETE FROM kernel_events WHERE delivered_at < ?');
        $statement->execute([$this->clock->now() - self::KEEP_DELIVERED_SECONDS]);

        return $statement->rowCount();
    }

    /**
     * @return ?array<string, mixed>
     */
    private function nextPending(): ?array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT id, type, payload, occurred_at FROM kernel_events '
                . 'WHERE delivered_at IS NULL AND failed_attempts < ? ORDER BY id LIMIT 1',
        );
        $statement->execute([self::MAX_FAILED_ATTEMPTS]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    private function deliverOne(int $id, string $type, string $payload, int $occurredAt): void
    {
        $this->database->transaction(function (\PDO $pdo) use ($id, $type, $payload, $occurredAt): void {
            // Marked first, so a second delivery of the same event (which the
            // tick lock already prevents) would change nothing.
            $mark = $pdo->prepare('UPDATE kernel_events SET delivered_at = ? WHERE id = ? AND delivered_at IS NULL');
            $mark->execute([$this->clock->now(), $id]);

            if ($mark->rowCount() !== 1) {
                return;
            }

            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                throw new \UnexpectedValueException('The payload is not a JSON object.');
            }

            $event = new Event($type, $decoded, $occurredAt);

            foreach ($this->subscribers as $subscriber) {
                if ($subscriber->handles($type)) {
                    $subscriber->handle($event);
                }
            }
        });
    }

    /**
     * @return int the event's failed attempts, this one included
     */
    private function recordFailure(int $id): int
    {
        return $this->database->transaction(function (\PDO $pdo) use ($id): int {
            $pdo->prepare('UPDATE kernel_events SET failed_attempts = failed_attempts + 1 WHERE id = ?')->execute([$id]);
            $statement = $pdo->prepare('SELECT failed_attempts FROM kernel_events WHERE id = ?');
            $statement->execute([$id]);

            return (int) $statement->fetchColumn();
        });
    }
}
