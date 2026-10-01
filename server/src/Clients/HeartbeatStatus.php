<?php

declare(strict_types=1);

namespace Maguari\Server\Clients;

/**
 * An enrolled instance's last contact, as of when it was read.
 */
final class HeartbeatStatus
{
    public function __construct(
        public readonly HeartbeatState $state,
        public readonly ?int $lastHeartbeatAt,
        public readonly ?int $ageSeconds,
        public readonly ?string $clientVersion,
    ) {
    }
}
