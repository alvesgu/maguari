<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\Events;

use Maguari\Server\Kernel\Database\Database;

/**
 * Writes domain events to the outbox, kernel_events (design section 2.1 rule
 * 5). Only inside an open transaction: the event must be committed together
 * with the change that caused it, or not at all.
 */
final class EventOutbox
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    /**
     * @return int the event's ID
     * @throws \LogicException outside a transaction
     */
    public function record(Event $event): int
    {
        if (!$this->database->inTransaction()) {
            throw new \LogicException(sprintf('The event %s must be recorded inside the transaction of its change.', $event->type));
        }

        $pdo = $this->database->pdo();
        $pdo->prepare('INSERT INTO kernel_events (type, payload, occurred_at) VALUES (?, ?, ?)')->execute([
            $event->type,
            json_encode($event->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $event->occurredAt,
        ]);

        return (int) $pdo->lastInsertId();
    }
}
