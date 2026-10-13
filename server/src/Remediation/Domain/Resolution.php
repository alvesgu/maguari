<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Domain;

/**
 * Why an incident resolved, stored in remediation_incidents.resolution.
 */
enum Resolution: string
{
    /** Its check passed for ResolutionRule::RESOLVE_AFTER_SECONDS. */
    case Passed = 'passed';

    /** Its subject is gone: a removed hostname or certificate. */
    case Withdrawn = 'withdrawn';

    /** The hourly reconciliation found Monitoring no longer failing it. */
    case Reconciled = 'reconciled';

    public function label(): string
    {
        return match ($this) {
            self::Passed => 'Passed',
            self::Withdrawn => 'Withdrawn',
            self::Reconciled => 'Reconciled',
        };
    }

    public function explanation(): string
    {
        return match ($this) {
            self::Passed => sprintf('The check passed for %d minutes.', intdiv(ResolutionRule::RESOLVE_AFTER_SECONDS, 60)),
            self::Withdrawn => 'What was checked is gone: a hostname was removed, or the instance no longer reports the certificate.',
            self::Reconciled => 'The hourly comparison with the checks\' current results found the check no longer failing.',
        };
    }
}
