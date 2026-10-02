<?php

declare(strict_types=1);

namespace Maguari\Shared;

/**
 * Metric names in heartbeat readings (design section 5.2). A metric is
 * "<kind>:<subject>", for example "disk_used_bytes:/". A kind never contains
 * the separator, so splitting at the first one is unambiguous even for mount
 * points that contain it.
 */
final class Metric
{
    public const SEPARATOR = ':';

    /** Bytes used on a filesystem; the subject is its mount point. */
    public const DISK_USED_BYTES = 'disk_used_bytes';

    /** Total bytes of a filesystem; the subject is its mount point. */
    public const DISK_TOTAL_BYTES = 'disk_total_bytes';

    public static function name(string $kind, string $subject): string
    {
        return $kind . self::SEPARATOR . $subject;
    }

    /**
     * @return array{string, ?string} the kind and the subject, which is null
     *         when the name has no separator
     */
    public static function split(string $metric): array
    {
        $position = strpos($metric, self::SEPARATOR);

        if ($position === false) {
            return [$metric, null];
        }

        return [substr($metric, 0, $position), substr($metric, $position + 1)];
    }
}
