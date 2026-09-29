<?php

declare(strict_types=1);

namespace Maguari\Server\Access;

final class SeedConfig
{
    /**
     * @param string[] $allowlist Lowercased, deduplicated email addresses.
     */
    public function __construct(
        public readonly string $administratorName,
        public readonly string $administratorEmail,
        public readonly array $allowlist,
    ) {
    }
}
