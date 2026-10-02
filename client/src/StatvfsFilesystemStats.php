<?php

declare(strict_types=1);

namespace Maguari\Client;

/**
 * PHP's disk_total_space() and disk_free_space(), which call statvfs() and
 * need no privileges (design section 5.4). disk_free_space() is the space
 * available to unprivileged users, so ext4's root reserve counts as used.
 */
final class StatvfsFilesystemStats implements FilesystemStats
{
    public function measure(string $mountPoint): ?array
    {
        // A mount point this user cannot reach only warns; it is skipped.
        $total = @disk_total_space($mountPoint);
        $free = @disk_free_space($mountPoint);

        if (!is_float($total) || !is_float($free) || $total < 0 || $free < 0 || $total >= PHP_INT_MAX) {
            return null;
        }

        return [(int) $total, (int) min($free, $total)];
    }
}
