<?php

declare(strict_types=1);

namespace Maguari\Client\Tests\Support;

use Maguari\Client\FilesystemStats;

/**
 * Fixed sizes by mount point; any other mount point cannot be measured.
 */
final class FakeFilesystemStats implements FilesystemStats
{
    /** @var list<string> */
    public array $measured = [];

    /**
     * @param array<string, array{int, int}> $sizes total and free bytes, by mount point
     */
    public function __construct(
        private readonly array $sizes = [],
    ) {
    }

    public function measure(string $mountPoint): ?array
    {
        $this->measured[] = $mountPoint;

        return $this->sizes[$mountPoint] ?? null;
    }
}
