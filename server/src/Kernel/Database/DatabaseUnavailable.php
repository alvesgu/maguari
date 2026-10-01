<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\Database;

/**
 * The database file cannot be created or opened. The message names the path
 * and the reason on one line.
 */
final class DatabaseUnavailable extends \RuntimeException
{
    public function __construct(
        public readonly string $path,
        string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(sprintf('Cannot use the database at %s: %s.', $path, $reason), 0, $previous);
    }
}
