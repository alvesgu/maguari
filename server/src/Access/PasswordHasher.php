<?php

declare(strict_types=1);

namespace Maguari\Server\Access;

final class PasswordHasher
{
    private ?string $dummyHash = null;

    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Spends the same time as a real verification, for unknown emails, so the
     * response time does not reveal which emails exist.
     */
    public function verifyDummy(string $password): void
    {
        $this->dummyHash ??= $this->hash(random_bytes(16));
        password_verify($password, $this->dummyHash);
    }
}
