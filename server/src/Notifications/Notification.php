<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

/**
 * One incident's notification, as one administrator sees it (design section
 * 10.4). Only facts: pages build the text when they are shown.
 */
final class Notification
{
    /**
     * @param int $instanceId Fleet's instance ID
     * @param string $subject empty for checks with one result per instance
     * @param ?int $resolvedAt null while the incident is open
     * @param bool $hidden whether the administrator it was read for hid it
     * @param string $detail the failed result's sentence when the incident
     *        opened, for the alert email
     * @param ?string $resolution why the incident resolved (Remediation's
     *        Resolution value); null while open or when Notifications'
     *        reconciliation resolved it
     */
    public function __construct(
        public readonly int $id,
        public readonly int $incidentId,
        public readonly int $instanceId,
        public readonly string $checkName,
        public readonly string $subject,
        public readonly int $openedAt,
        public readonly ?int $resolvedAt,
        public readonly bool $hidden,
        public readonly string $detail = '',
        public readonly ?string $resolution = null,
    ) {
    }
}
