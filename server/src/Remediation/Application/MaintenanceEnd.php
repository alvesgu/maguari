<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Application;

use Maguari\Server\Remediation\Domain\RemediationLimits;
use Maguari\Server\Remediation\Exception\InvalidMaintenanceEnd;

/**
 * A maintenance's end time as a datetime-local form field sends it, in UTC
 * (design section 7.2 item 8). Required, like a snooze's.
 */
final class MaintenanceEnd
{
    private const FORMAT = 'Y-m-d\TH:i';

    /**
     * @throws InvalidMaintenanceEnd
     */
    public static function parse(string $input, int $now): int
    {
        $input = trim($input);
        $parsed = \DateTimeImmutable::createFromFormat('!' . self::FORMAT, $input, new \DateTimeZone('UTC'));

        // The round trip refuses dates PHP would roll over, such as February 31.
        if ($parsed === false || $parsed->format(self::FORMAT) !== $input) {
            throw new InvalidMaintenanceEnd('Enter when the maintenance ends, as a date and a time in UTC.');
        }

        $until = $parsed->getTimestamp();

        if ($until <= $now) {
            throw new InvalidMaintenanceEnd('A maintenance must end in the future.');
        }

        if ($until > $now + RemediationLimits::MAX_MAINTENANCE_SECONDS) {
            throw new InvalidMaintenanceEnd(sprintf('A maintenance can last at most %d days.', intdiv(RemediationLimits::MAX_MAINTENANCE_SECONDS, 86_400)));
        }

        return $until;
    }
}
