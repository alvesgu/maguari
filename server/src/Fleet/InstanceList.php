<?php

declare(strict_types=1);

namespace Maguari\Server\Fleet;

/**
 * The instances of one project as listed from the Compute Engine API.
 */
final class InstanceList
{
    /**
     * @param DiscoveredInstance[] $instances sorted by name, then zone
     * @param string[] $unreachableZones zones (or regions) Google could not list, sorted
     * @param bool $truncated true when the project has more instances than Maguari lists
     */
    public function __construct(
        public readonly array $instances,
        public readonly array $unreachableZones,
        public readonly bool $truncated,
    ) {
    }
}
