<?php

declare(strict_types=1);

namespace Maguari\Server\Fleet\Gcp;

/**
 * A Google OAuth access token. Lives in memory only: never stored or logged.
 */
final class AccessToken
{
    public function __construct(
        #[\SensitiveParameter]
        public readonly string $value,
        public readonly int $expiresAt,
    ) {
    }

    /**
     * Keeps the token out of var_dump() and print_r().
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['value' => '(hidden)', 'expiresAt' => $this->expiresAt];
    }
}
