<?php

declare(strict_types=1);

namespace Maguari\Server\Access;

final class Administrator
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
    ) {
    }
}
