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
     * @param string $subject what on the instance was checked, for checks
     *        with more than one result per instance (a certificate's domain);
     *        empty otherwise
     */
    public function __construct(
        public readonly int $instanceId,
        public readonly string $checkName,
        public readonly CheckOutcome $outcome,
        public readonly string $detail,
        public readonly int $checkedAt,
        public readonly string $subject = '',
    ) {
    }
}
