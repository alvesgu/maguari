<?php

declare(strict_types=1);

namespace Maguari\Server\Fleet;

/**
 * An instance an administrator picked for enrollment. Other contexts refer to
 * it by $id (design section 2.1 rule 3).
 */
final class Instance
{
    /**
     * @param string $gcpInstanceId unsigned 64-bit, so it may not fit in a PHP int
     * @param string $zone short name, for example us-central1-a
     */
    public function __construct(
        public readonly int $id,
        public readonly int $projectId,
        public readonly string $gcpProjectId,
        public readonly string $gcpInstanceId,
        public readonly string $zone,
        public readonly string $name,
        public readonly int $pickedAt,
    ) {
    }
}
