<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Event;

/**
 * A check passed where it had failed or had no outcome yet.
 */
final class CheckPassed extends CheckOutcomeChanged
{
    public const TYPE = 'monitoring.check_passed';
}
