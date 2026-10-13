<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

/**
 * Notifications' own names for checks, for its pages and, from step 1.5, its
 * emails. A notification refers to a check by name only, so Notifications
 * needs nothing else from Monitoring.
 */
final class CheckLabels
{
    private const LABELS = [
        'heartbeat_age' => 'Heartbeat age',
        'disk_size' => 'Disk size',
        'local_certificate' => 'Certificate on the instance',
        'remote_certificate' => 'Served certificate',
    ];

    /**
     * The check's own name when it has no label, so a newer check still shows.
     */
    public static function of(string $checkName): string
    {
        return self::LABELS[$checkName] ?? $checkName;
    }
}
