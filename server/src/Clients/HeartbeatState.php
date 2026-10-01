<?php

declare(strict_types=1);

namespace Maguari\Server\Clients;

/**
 * How recent an enrolled client's last heartbeat is. Display only.
 *
 * TEMPORARY (MVP): to be replaced by Monitoring's heartbeat-age check, based
 * on each instance's interval (design sections 5.2 and 6.2). See
 * ClientsApi::LATE_AFTER_SECONDS.
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
