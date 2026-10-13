<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Event;

/**
 * A check failed where it had passed or had no outcome yet.
 */
final class CheckFailed extends CheckOutcomeChanged
{
    public const TYPE = 'monitoring.check_failed';
}
