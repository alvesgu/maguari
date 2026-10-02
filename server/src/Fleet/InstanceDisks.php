<?php

declare(strict_types=1);

namespace Maguari\Server\Fleet;

/**
 * A picked instance's attached disks from the Compute Engine API, or the
 * fixed sentence saying why they could not be listed.
 */
final class InstanceDisks
{
    /**
     * @param AttachedDisk[] $disks
     */
    private function __construct(
        public readonly array $disks,
        public readonly ?string $problem,
    ) {
    }

    /**
     * @param AttachedDisk[] $disks
     */
    public static function listed(array $disks): self
    {
        return new self($disks, null);
    }

    public static function unavailable(string $problem): self
    {
        return new self([], $problem);
    }

    public function bootDisk(): ?AttachedDisk
    {
        foreach ($this->disks as $disk) {
            if ($disk->boot) {
                return $disk;
            }
        }

        return null;
    }
}
