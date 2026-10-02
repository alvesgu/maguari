<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

use Maguari\Server\Monitoring\Exception\InvalidReadings;
use Maguari\Shared\Metric;

/**
 * The validated readings of one heartbeat (design section 5.2). Readings of
 * unknown kinds are dropped, so newer clients keep working with older servers.
 */
final class Readings
{
    /** Bounds the database writes per heartbeat. */
    public const MAX_READINGS = 100;

    public const MAX_MOUNT_POINT_BYTES = 1024;

    /**
     * Disk used continues its run while it stays within 1/1000 (0.1%) of the
     * filesystem's total from the run's value (design section 9.1).
     */
    public const DISK_USED_DEADBAND_DIVISOR = 1000;

    private const DISK_KINDS = [Metric::DISK_USED_BYTES, Metric::DISK_TOTAL_BYTES];

    /**
     * @param list<Reading> $readings
     */
    private function __construct(
        public readonly array $readings,
    ) {
    }

    /**
     * @param list<mixed> $items the heartbeat's readings list, as decoded from JSON
     * @throws InvalidReadings
     */
    public static function parse(array $items): self
    {
        if (count($items) > self::MAX_READINGS) {
            throw new InvalidReadings(sprintf('At most %d readings are accepted.', self::MAX_READINGS));
        }

        /** @var array<string, int> $values known readings, by metric */
        $values = [];
        $seen = [];

        foreach ($items as $item) {
            $metric = is_array($item) ? ($item['metric'] ?? null) : null;
            $value = is_array($item) ? ($item['value'] ?? null) : null;

            if (!is_string($metric) || !is_int($value)) {
                throw new InvalidReadings('Each reading must be an object with a string metric and an integer value.');
            }

            if ($value < 0) {
                throw new InvalidReadings('A reading cannot be negative.');
            }

            if (isset($seen[$metric])) {
                throw new InvalidReadings('A metric appears twice.');
            }

            $seen[$metric] = true;
            [$kind, $mountPoint] = Metric::split($metric);

            if (!in_array($kind, self::DISK_KINDS, true)) {
                continue;
            }

            if (!self::isMountPoint($mountPoint)) {
                throw new InvalidReadings('A disk metric must name an absolute mount point.');
            }

            $values[$metric] = $value;
        }

        $readings = [];

        foreach ($values as $metric => $value) {
            $deadband = 0;
            [$kind, $mountPoint] = Metric::split($metric);

            if ($kind === Metric::DISK_USED_BYTES) {
                $total = $values[Metric::name(Metric::DISK_TOTAL_BYTES, (string) $mountPoint)]
                    ?? throw new InvalidReadings('Disk used must come with the total of the same filesystem.');
                $deadband = intdiv($total, self::DISK_USED_DEADBAND_DIVISOR);
            }

            $readings[] = new Reading($metric, $value, $deadband);
        }

        return new self($readings);
    }

    private static function isMountPoint(?string $mountPoint): bool
    {
        return $mountPoint !== null
            && str_starts_with($mountPoint, '/')
            && strlen($mountPoint) <= self::MAX_MOUNT_POINT_BYTES
            && preg_match('/[\x00-\x1f\x7f]/', $mountPoint) !== 1;
    }
}
