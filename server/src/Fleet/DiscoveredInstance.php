<?php

declare(strict_types=1);

namespace Maguari\Server\Fleet;

/**
 * An instance listed from the Compute Engine API but not yet enrolled.
 */
final class DiscoveredInstance
{
    /**
     * @param string $gcpInstanceId unsigned 64-bit, so it may not fit in a PHP int
     * @param string $zone short name, for example us-central1-a
     * @param string $machineType short name, for example e2-micro; empty when not reported
     */
    public function __construct(
        public readonly string $gcpInstanceId,
        public readonly string $name,
        public readonly string $zone,
        public readonly InstanceStatus $status,
        public readonly string $machineType,
    ) {
    }
}
