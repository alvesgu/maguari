<?php

declare(strict_types=1);

namespace Maguari\Server\Fleet;

final class Project
{
    public function __construct(
        public readonly int $id,
        public readonly string $gcpProjectId,
        public readonly int $createdAt,
    ) {
    }
}
