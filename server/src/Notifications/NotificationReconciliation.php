<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

/**
 * What one daily reconciliation with Remediation changed. In normal operation
 * both lists are empty: anything here points at a lost event.
 */
final class NotificationReconciliation
{
    /**
     * @param list<Notification> $created for open incidents that had none
     * @param list<Notification> $resolved whose incident is no longer open
     */
    public function __construct(
        public readonly int $ranAt,
        public readonly array $created,
        public readonly array $resolved,
    ) {
    }
}
