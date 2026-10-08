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

    /**
     * The worst of $outcomes: a failure over a check that could not run over
     * a pass. Null when there are none.
     *
     * @param CheckOutcome[] $outcomes
     */
    public static function worst(array $outcomes): ?self
    {
        foreach ([self::Fail, self::NotChecked, self::Pass] as $outcome) {
            if (in_array($outcome, $outcomes, true)) {
                return $outcome;
            }
        }

        return null;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pass => 'Pass',
            self::Fail => 'Fail',
            self::NotChecked => 'Not checked',
        };
    }
}
