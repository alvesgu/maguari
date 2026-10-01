<?php

declare(strict_types=1);

namespace Maguari\Server\Fleet;

use Maguari\Server\Fleet\Exception\InvalidInstanceName;

/**
 * Validates a zone and instance name before they are put into an API URL.
 */
final class InstanceName
{
    // For example us-central1-a or europe-west4-b.
    private const ZONE_PATTERN = '/^[a-z]+-[a-z]+[0-9]+-[a-z]$/D';
    // RFC 1035 labels, as Compute Engine requires for instance names.
    private const NAME_PATTERN = '/^[a-z](?:[-a-z0-9]{0,61}[a-z0-9])?$/D';

    /**
     * @throws InvalidInstanceName
     */
    public static function validate(string $zone, string $name): void
    {
        if (preg_match(self::ZONE_PATTERN, $zone) !== 1 || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidInstanceName('This is not a valid zone and instance name.');
        }
    }
}
