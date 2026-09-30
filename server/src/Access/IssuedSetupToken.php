<?php

declare(strict_types=1);

namespace Maguari\Server\Access;

final class IssuedSetupToken
{
    public function __construct(
        public readonly string $token,
        public readonly int $expiresAt,
    ) {
    }
}
