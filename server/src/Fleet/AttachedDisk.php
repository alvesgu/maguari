<?php

declare(strict_types=1);

namespace Maguari\Server\Fleet;

/**
 * A disk attached to an instance, as listed from the Compute Engine API.
 */
final class AttachedDisk
{
    /**
     * @param string $deviceName the name the disk has inside the instance
     *        (/dev/disk/by-id/google-<deviceName>); empty when not reported
     * @param ?int $sizeBytes null when the API did not report a usable size
     */
    public function __construct(
        public readonly string $deviceName,
        public readonly bool $boot,
        public readonly ?int $sizeBytes,
    ) {
    }
}
