<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Domain;

/**
 * The part of the ladder an incident may use (Ladder::path()).
 */
enum LadderPath
{
    /** Restart, restart again, reboot, reset. */
    case Full;

    /** The client is silent, so only a reset can reach the instance. */
    case ResetOnly;

    case AlertOnly;
}
