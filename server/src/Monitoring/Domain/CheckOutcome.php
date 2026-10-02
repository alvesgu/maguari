<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * The result of one check, stored in monitoring_check_results.outcome.
 */
enum CheckOutcome: string
{
    case Pass = 'pass';
    case Fail = 'fail';

    /** The check could not run, for example without readings or API access. */
    case NotChecked = 'not_checked';

    public function label(): string
    {
        return match ($this) {
            self::Pass => 'Pass',
            self::Fail => 'Fail',
            self::NotChecked => 'Not checked',
        };
    }
}
