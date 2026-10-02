<?php

declare(strict_types=1);

namespace Maguari\Client;

use Maguari\Shared\Metric;

/**
 * Disk used and total of each real local filesystem (design section 6.1), as
 * heartbeat readings. Failures never stop a heartbeat: a filesystem that
 * cannot be measured is skipped, and an unreadable mounts file gives no
 * readings.
 */
final class DiskUsage
{
    public const MOUNTS_PATH = '/proc/self/mounts';

    /** Real local filesystems on Ubuntu instances. */
    public const FILESYSTEM_TYPES = ['ext2', 'ext3', 'ext4', 'xfs', 'btrfs', 'vfat'];

    /** At most 40 readings per heartbeat. */
    public const MAX_FILESYSTEMS = 20;

    /** The server rejects longer mount points. */
    private const MAX_MOUNT_POINT_BYTES = 1024;

    public function __construct(
        private readonly FilesystemStats $stats,
        private readonly string $mountsPath = self::MOUNTS_PATH,
    ) {
    }

    /**
     * @return list<array{metric: string, value: int}>
     */
    public function readings(): array
    {
        $readings = [];

        foreach ($this->mountPoints() as $mountPoint) {
            $measured = $this->stats->measure($mountPoint);

            if ($measured === null) {
                continue;
            }

            [$total, $free] = $measured;
            $readings[] = ['metric' => Metric::name(Metric::DISK_USED_BYTES, $mountPoint), 'value' => $total - $free];
            $readings[] = ['metric' => Metric::name(Metric::DISK_TOTAL_BYTES, $mountPoint), 'value' => $total];
        }

        return $readings;
    }

    /**
     * The mount points to measure, in the mounts file's order: real local
     * filesystem types on a device under /dev/, each device once (a bind
     * mount repeats it), at most MAX_FILESYSTEMS.
     *
     * @return list<string>
     */
    public function mountPoints(): array
    {
        $contents = @file_get_contents($this->mountsPath);

        if ($contents === false) {
            return [];
        }

        $mountPoints = [];
        $devices = [];

        foreach (explode("\n", $contents) as $line) {
            $fields = explode(' ', $line);

            if (count($fields) < 3) {
                continue;
            }

            [$device, $mountPoint, $type] = array_map(self::unescape(...), array_slice($fields, 0, 3));

            if (
                !in_array($type, self::FILESYSTEM_TYPES, true)
                || !str_starts_with($device, '/dev/')
                || isset($devices[$device])
                || !self::isAcceptedMountPoint($mountPoint)
            ) {
                continue;
            }

            $devices[$device] = true;
            $mountPoints[] = $mountPoint;

            if (count($mountPoints) === self::MAX_FILESYSTEMS) {
                break;
            }
        }

        return $mountPoints;
    }

    /**
     * The kernel writes a space, tab, newline or backslash in a field as an
     * octal escape, for example \040 for a space.
     */
    private static function unescape(string $field): string
    {
        return (string) preg_replace_callback('/\\\\([0-7]{3})/', static fn (array $match): string => chr(octdec($match[1]) & 0xff), $field);
    }

    /**
     * The server rejects the whole heartbeat for one bad mount point (design
     * section 5.2), so such mount points are skipped here.
     */
    private static function isAcceptedMountPoint(string $mountPoint): bool
    {
        return str_starts_with($mountPoint, '/')
            && strlen($mountPoint) <= self::MAX_MOUNT_POINT_BYTES
            && preg_match('/[\x00-\x1f\x7f]/', $mountPoint) !== 1;
    }
}
