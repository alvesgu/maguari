<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Domain;

/**
 * When an open incident whose check passes resolves (design section 7.4).
 */
final class ResolutionRule
{
    /**
     * The check must pass continuously this long, so a check that flaps does
     * not open and resolve incidents over and over.
     */
    public const RESOLVE_AFTER_SECONDS = 300;

    public static function isDue(?int $passingSince, int $at): bool
    {
        return $passingSince !== null && $at - $passingSince >= self::RESOLVE_AFTER_SECONDS;
    }
}
