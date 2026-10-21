<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

use Maguari\Shared\Protocol;
use Maguari\Shared\ServiceCheck;

/**
 * Turns a service result into Maguari's own sentence (design section 6.1.3).
 */
final class ServiceCheckRule
{
    /**
     * @param int $checkedAt the server's receive time of the heartbeat
     */
    public static function result(int $instanceId, ClientCheckResult $result, int $checkedAt): CheckResult
    {
        $unit = $result->subject;
        $detail = match (true) {
            $result->outcome === CheckOutcome::Pass => sprintf('%s is %s.', $unit, $result->state),
            $result->reason === ServiceCheck::REASON_INACTIVE => sprintf('%s is %s, not active.', $unit, $result->state),
            $result->reason === ServiceCheck::REASON_NOT_FOUND => sprintf('There is no unit %s on the instance.', $unit),
            $result->reason === Protocol::CHECK_REASON_TIME_LIMIT => 'The client ran out of time for its checks before this one.',
            default => sprintf('The client could not run systemctl to read the state of %s.', $unit),
        };

        return new CheckResult($instanceId, ServiceCheck::TYPE, $result->outcome, $detail, $checkedAt, $unit);
    }
}
