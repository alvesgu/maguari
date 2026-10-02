<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring;

/**
 * Compares an instance's boot disk with the filesystems on it, to find a grown
 * disk whose filesystem was never extended (design section 6.3). No database
 * or API access.
 */
final class DiskSizeRule
{
    public const CHECK_NAME = 'disk_size';

    /** The filesystems of a Compute Engine Ubuntu image's boot disk. */
    public const BOOT_DISK_MOUNT_POINTS = ['/', '/boot', '/boot/efi'];

    /**
     * A filesystem is always smaller than its disk (partition table, the EFI
     * partition, ext4's own space), by about 4% on a 10 GiB disk. Growing that
     * disk by 1 GiB leaves about 13% unaccounted for.
     */
    public const MAX_UNACCOUNTED_PERCENT = 10;

    /** Readings older than this are not used. */
    public const MAX_READING_AGE_SECONDS = 86_400;

    private const BYTES_PER_GIB = 1 << 30;

    /**
     * @param ?string $problem why the boot disk's size is unknown, from Fleet
     * @param ?int $bootDiskBytes the boot disk's size; null when not reported
     * @param array<string, ?MetricRun> $filesystemTotals the current disk_total_bytes
     *        run of each mount point in BOOT_DISK_MOUNT_POINTS, null when none
     * @param int $at now
     */
    public function check(int $instanceId, ?string $problem, ?int $bootDiskBytes, array $filesystemTotals, int $at): CheckResult
    {
        $result = fn (CheckOutcome $outcome, string $detail): CheckResult => new CheckResult($instanceId, self::CHECK_NAME, $outcome, $detail, $at);

        if ($problem !== null) {
            return $result(CheckOutcome::NotChecked, $problem);
        }

        if ($bootDiskBytes === null) {
            return $result(CheckOutcome::NotChecked, 'Google Cloud reported no boot disk size for this instance.');
        }

        $recent = array_filter(
            $filesystemTotals,
            static fn (?MetricRun $run): bool => $run !== null && $at - $run->endAt <= self::MAX_READING_AGE_SECONDS,
        );

        // Without /, the sum would miss most of the disk and fail falsely.
        if (!isset($recent['/'])) {
            return $result(CheckOutcome::NotChecked, $recent === []
                ? 'No disk readings in the last 24 hours.'
                : 'No reading for / in the last 24 hours, so the boot disk cannot be compared.');
        }

        $filesystemBytes = array_sum(array_map(static fn (MetricRun $run): int|float => $run->value, $recent));
        $unaccounted = $bootDiskBytes - $filesystemBytes;
        $sizes = sprintf('The boot disk is %s', self::gib($bootDiskBytes));

        if (100 * $unaccounted > self::MAX_UNACCOUNTED_PERCENT * $bootDiskBytes) {
            return $result(CheckOutcome::Fail, sprintf(
                '%s, but its filesystems total %s. Rebooting usually extends them (cloud-init); otherwise run growpart and resize2fs.',
                $sizes,
                self::gib($filesystemBytes),
            ));
        }

        return $result(CheckOutcome::Pass, sprintf('%s and its filesystems total %s.', $sizes, self::gib($filesystemBytes)));
    }

    private static function gib(int|float $bytes): string
    {
        return sprintf('%.1f GiB', $bytes / self::BYTES_PER_GIB);
    }
}
