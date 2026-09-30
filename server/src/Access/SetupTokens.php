<?php

declare(strict_types=1);

namespace Maguari\Server\Access;

use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;

/**
 * One-time setup tokens (design section 11.2). At most one exists at a time and
 * only its SHA-256 hash is stored.
 */
final class SetupTokens
{
    public const LIFETIME_SECONDS = 3600;

    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Invalidates any previous token.
     */
    public function issue(): IssuedSetupToken
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = $this->clock->now();
        $expiresAt = $now + self::LIFETIME_SECONDS;

        $this->database->transaction(function (\PDO $pdo) use ($token, $now, $expiresAt): void {
            $pdo->exec('DELETE FROM access_setup_tokens');
            $pdo->prepare('INSERT INTO access_setup_tokens (token_hash, created_at, expires_at) VALUES (?, ?, ?)')
                ->execute([self::hash($token), $now, $expiresAt]);
        });

        return new IssuedSetupToken($token, $expiresAt);
    }

    public function isValid(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        // Looked up by hash, so the plain token is never compared directly.
        $statement = $this->database->pdo()->prepare(
            'SELECT 1 FROM access_setup_tokens WHERE token_hash = ? AND expires_at > ?',
        );
        $statement->execute([self::hash($token), $this->clock->now()]);

        return $statement->fetchColumn() !== false;
    }

    public function consume(string $token): void
    {
        $this->database->pdo()->prepare('DELETE FROM access_setup_tokens WHERE token_hash = ?')
            ->execute([self::hash($token)]);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
