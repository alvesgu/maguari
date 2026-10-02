<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * The result of one check on one instance.
 */
final class CheckResult
{
    /**
     * @param int $instanceId Fleet's instance ID
     * @param string $detail a fixed sentence for the administrator
     */
    public function __construct(
        public readonly int $instanceId,
        public readonly string $checkName,
        public readonly CheckOutcome $outcome,
        public readonly string $detail,
        public readonly int $checkedAt,
    ) {
    }
}
