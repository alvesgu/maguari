<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring;

enum RunDecision
{
    /** Start a new run at the reading's time. */
    case Insert;

    /** Move the current run's end to the reading's time. */
    case Extend;

    /** Drop the reading. */
    case Ignore;
}
