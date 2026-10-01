<?php

declare(strict_types=1);

namespace Maguari\Client;

/**
 * The client's version, from client/VERSION (design section 4).
 */
final class Version
{
    public static function current(): string
    {
        $version = trim((string) @file_get_contents(dirname(__DIR__) . '/VERSION'));

        if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version) !== 1) {
            throw new ClientFailure('The client\'s VERSION file is missing or invalid.');
        }

        return $version;
    }
}
