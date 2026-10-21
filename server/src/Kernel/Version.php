<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel;

/**
 * The server's version, from server/VERSION (design section 4).
 */
final class Version
{
    public static function current(): string
    {
        $version = trim((string) @file_get_contents(dirname(__DIR__, 2) . '/VERSION'));

        if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version) !== 1) {
            throw new \RuntimeException('The server\'s VERSION file is missing or invalid.');
        }

        return $version;
    }
}
