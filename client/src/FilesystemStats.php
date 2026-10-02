<?php

declare(strict_types=1);

namespace Maguari\Client;

/**
 * Measures one mounted filesystem.
 */
interface FilesystemStats
{
    /**
     * @return array{int, int}|null total bytes and bytes available to
     *         unprivileged users, or null when the filesystem cannot be measured
     */
    public function measure(string $mountPoint): ?array;
}
