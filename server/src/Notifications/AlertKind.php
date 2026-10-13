<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

/**
 * Which alert email, stored in notifications_alerts.kind.
 */
enum AlertKind: string
{
    case Opened = 'opened';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Opened => 'Opened',
            self::Resolved => 'Resolved',
        };
    }
}
