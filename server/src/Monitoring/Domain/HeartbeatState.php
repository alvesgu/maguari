<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * How recent an instance's last heartbeat is, for display (design section
 * 5.2).
 */
enum HeartbeatState
{
    case NoHeartbeatYet;
    case OnTime;
    case Late;

    public function label(): string
    {
        return match ($this) {
            self::NoHeartbeatYet => 'No heartbeat yet',
            self::OnTime => 'On time',
            self::Late => 'Late',
        };
    }
}
