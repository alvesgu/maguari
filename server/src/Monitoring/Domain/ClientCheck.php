<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

use Maguari\Shared\LocalHttpCheck;
use Maguari\Shared\ServiceCheck;

/**
 * A check one instance's client runs (design section 6.1.3), as an
 * administrator set it in the web app. Its results' check name is its type
 * and their subject its target.
 */
final class ClientCheck
{
    /** Bounds the client's work per heartbeat; service checks take milliseconds. */
    public const MAX_SERVICES_PER_INSTANCE = 20;

    /** Each can take up to 10 seconds, within the client's 20 for all checks. */
    public const MAX_LOCAL_HTTP_PER_INSTANCE = 5;

    /**
     * @param int $instanceId Fleet's instance ID
     * @param string $type ServiceCheck::TYPE or LocalHttpCheck::TYPE (later database)
     * @param string $target the unit name for a service check, the URL for a local HTTP check
     * @param string $hostName only for local HTTP checks; '' otherwise
     */
    public function __construct(
        public readonly int $id,
        public readonly int $instanceId,
        public readonly string $type,
        public readonly string $target,
        public readonly string $hostName,
        public readonly int $addedAt,
    ) {
    }

    public function checkName(): string
    {
        return $this->type;
    }

    public function subject(): string
    {
        return $this->type === LocalHttpCheck::TYPE ? LocalHttpCheck::subject($this->target, $this->hostName) : $this->target;
    }

    /**
     * What the client receives for this check (design section 5.2).
     *
     * @return array<string, string>
     */
    public function setting(): array
    {
        return match ($this->type) {
            ServiceCheck::TYPE => ['type' => ServiceCheck::TYPE, 'unit' => $this->target],
            LocalHttpCheck::TYPE => ['type' => LocalHttpCheck::TYPE, 'url' => $this->target, 'host_name' => $this->hostName],
            default => throw new \LogicException(sprintf('Unknown client check type %s.', $this->type)),
        };
    }
}
