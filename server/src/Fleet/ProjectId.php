<?php

declare(strict_types=1);

namespace Maguari\Server\Fleet;

use Maguari\Server\Fleet\Exception\InvalidProjectId;

/**
 * GCP project IDs: 6 to 30 characters, lowercase letters, digits and hyphens,
 * starting with a letter and not ending with a hyphen. Legacy domain-scoped IDs
 * (example.com:my-project) are not supported.
 */
final class ProjectId
{
    private const PATTERN = '/^[a-z][a-z0-9-]{4,28}[a-z0-9]$/';

    /**
     * @throws InvalidProjectId
     */
    public static function normalize(string $input): string
    {
        $id = strtolower(trim($input));

        if (preg_match(self::PATTERN, $id) !== 1) {
            throw new InvalidProjectId(
                'Enter a valid project ID: 6 to 30 lowercase letters, digits or hyphens, '
                    . 'starting with a letter and not ending with a hyphen.',
            );
        }

        return $id;
    }
}
