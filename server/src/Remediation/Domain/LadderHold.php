<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Domain;

/**
 * Why the ladder is waiting on an incident, for its page. Stored in
 * remediation_incidents.ladder_hold.
 */
enum LadderHold: string
{
    case RestartLimit = 'restart_limit';
    case ResetLimit = 'reset_limit';

    public function sentence(): string
    {
        return match ($this) {
            self::RestartLimit => sprintf('The instance reached its limit of %d restarts per hour. The ladder goes on when the hour allows another.', RemediationLimits::MAX_RESTARTS_PER_HOUR),
            self::ResetLimit => sprintf('The instance reached its limit of %d resets in 24 hours. The ladder goes on when the 24 hours allow another.', RemediationLimits::MAX_RESETS_PER_24_HOURS),
        };
    }
}
