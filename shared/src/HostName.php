<?php

declare(strict_types=1);

namespace Maguari\Shared;

/**
 * A DNS host name as Maguari accepts one (design section 6.3.2): lowercase
 * labels of letters, digits and hyphens (not at either end), at most 63
 * bytes each and 253 in all, at least two labels, the last not all digits.
 * So no IP addresses, no localhost and no wildcards.
 */
final class HostName
{
    public static function isValid(string $name): bool
    {
        if ($name === '' || strlen($name) > 253) {
            return false;
        }

        $labels = explode('.', $name);

        if (count($labels) < 2 || preg_match('/^[0-9]+$/D', $labels[count($labels) - 1]) === 1) {
            return false;
        }

        foreach ($labels as $label) {
            if (strlen($label) > 63 || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/D', $label) !== 1) {
                return false;
            }
        }

        return true;
    }
}
