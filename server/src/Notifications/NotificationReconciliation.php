<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

/**
 * What one hourly reconciliation with Remediation changed. In normal operation
 * every list is empty: anything here points at a lost event or at alert
 * emails that could not be sent.
 */
final class NotificationReconciliation
{
    /**
     * @param list<Notification> $created for open incidents that had none
     * @param list<Notification> $resolved whose incident is no longer open
     * @param list<Notification> $lateAlerts whose incident is still open and
     *        whose "opened" email was never sent, queued again, late
     */
    public function __construct(
        public readonly int $ranAt,
        public readonly array $created,
        public readonly array $resolved,
        public readonly array $lateAlerts = [],
    ) {
    }
}
