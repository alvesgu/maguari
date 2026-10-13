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
     * A check Notifications does not know yet (phase 2 adds checks) gets a
     * generic label that still names it, for example "Other check
     * (http_status)", so a notification or email never fails on it.
     */
    public static function of(string $checkName): string
    {
        if (isset(self::LABELS[$checkName])) {
            return self::LABELS[$checkName];
        }

        $name = preg_replace('/[^a-z0-9_]/', '', strtolower($checkName)) ?? '';

        return $name === '' ? 'Other check' : "Other check ({$name})";
    }
}
