<?php

declare(strict_types=1);

namespace Maguari\Server\Clients;

/**
 * How recent an enrolled client's last heartbeat is. Display only: the
 * heartbeat-age check (design section 6.2) comes with Monitoring.
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
