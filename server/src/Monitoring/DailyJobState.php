<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring;

/**
 * Where a run of the daily job stands.
 */
enum DailyJobState
{
    /** Unfinished and started less than 15 minutes ago. */
    case Running;

    /** Unfinished and older: the process was killed (design section 6.3). */
    case Killed;

    /** It hit an error, which was logged. No results. */
    case Failed;

    case Succeeded;
}
