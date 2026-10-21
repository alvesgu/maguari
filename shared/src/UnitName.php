<?php

declare(strict_types=1);

namespace Maguari\Shared;

/**
 * systemd's unit name rules, restricted to services (design section 6.1.3):
 * `<name>.service` or `<name>@<instance>.service`, at most 255 bytes, of
 * letters, digits and `: _ . \ -`, with one `@` before a non-empty instance,
 * never starting with `-` or `.`. The server validates what it stores and the
 * client validates again what it receives, so the client never runs systemctl
 * on anything else.
 */
final class UnitName
{
    public const SUFFIX = '.service';
    public const MAX_BYTES = 255;

    public static function isValid(string $unit): bool
    {
        return strlen($unit) <= self::MAX_BYTES
            && preg_match('/^[A-Za-z0-9:_\\\\][A-Za-z0-9:_.\\\\-]*(@[A-Za-z0-9:_.\\\\-]+)?\.service$/D', $unit) === 1;
    }
}
