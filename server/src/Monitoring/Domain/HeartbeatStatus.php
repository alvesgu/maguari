<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * An instance's last heartbeat, as of when it was read.
 */
final class HeartbeatStatus
{
    public function __construct(
        public readonly HeartbeatState $state,
        public readonly ?int $lastHeartbeatAt,
        public readonly ?int $ageSeconds,
    ) {
    }
}
