<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

use Maguari\Server\Monitoring\Exception\InvalidClientCheck;
use Maguari\Shared\UnitName;

/**
 * The unit name of a service check, as an administrator types it (design
 * section 6.1.3). Every rejection has its own sentence; what passes also
 * passes the client's own check (UnitName).
 */
final class ServiceUnit
{
    /**
     * The unit as stored: trimmed, with .service added when no suffix was typed.
     *
     * @throws InvalidClientCheck
     */
    public static function normalize(string $input): string
    {
        $unit = trim($input);

        if ($unit === '') {
            throw new InvalidClientCheck('Enter a service name, for example nginx or nginx.service.');
        }

        if (preg_match('/\.(socket|target|timer|mount|automount|path|slice|scope|swap|device)$/D', $unit) === 1) {
            throw new InvalidClientCheck('Only services can be checked: enter a name ending in .service, or leave the suffix out.');
        }

        if (!str_ends_with($unit, UnitName::SUFFIX)) {
            $unit .= UnitName::SUFFIX;
        }

        if (strlen($unit) > UnitName::MAX_BYTES) {
            throw new InvalidClientCheck(sprintf('A service name has at most %d characters.', UnitName::MAX_BYTES));
        }

        if ($unit[0] === '-' || $unit[0] === '.') {
            throw new InvalidClientCheck('A service name cannot start with - or a dot.');
        }

        if (preg_match('/^[^@]+@\.service$/D', $unit) === 1) {
            throw new InvalidClientCheck(sprintf('%s is a template: enter one of its instances, for example %s.', $unit, str_replace('@.service', '@tty1.service', $unit)));
        }

        if (!UnitName::isValid($unit)) {
            throw new InvalidClientCheck('A service name has only letters, digits and : _ . \\ - characters, and at most one @ before a template\'s instance.');
        }

        return $unit;
    }
}
