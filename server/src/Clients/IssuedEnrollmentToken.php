<?php

declare(strict_types=1);

namespace Maguari\Server\Clients;

/**
 * A newly issued enrollment token. The plain token exists only here: it is
 * shown once and never stored.
 */
final class IssuedEnrollmentToken
{
    public function __construct(
        public readonly string $token,
        public readonly int $expiresAt,
    ) {
    }
}
