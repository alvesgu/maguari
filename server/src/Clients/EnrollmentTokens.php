<?php

declare(strict_types=1);

namespace Maguari\Server\Clients;

use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;

/**
 * One-time enrollment tokens, each bound to one instance (design section 5.6).
 * Only the SHA-256 hash is stored.
 */
final class EnrollmentTokens
{
    public const LIFETIME_SECONDS = 3600;

    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Replaces any earlier token for the instance and deletes expired ones.
     */
    public function issue(int $instanceId): IssuedEnrollmentToken
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = $this->clock->now();
        $expiresAt = $now + self::LIFETIME_SECONDS;

        $this->database->transaction(function (\PDO $pdo) use ($token, $instanceId, $now, $expiresAt): void {
            $pdo->prepare('DELETE FROM clients_enrollment_tokens WHERE instance_id = ? OR expires_at <= ?')
                ->execute([$instanceId, $now]);
            $pdo->prepare('INSERT INTO clients_enrollment_tokens (token_hash, instance_id, created_at, expires_at) VALUES (?, ?, ?, ?)')
                ->execute([self::hash($token), $instanceId, $now, $expiresAt]);
        });

        return new IssuedEnrollmentToken($token, $expiresAt);
    }

    /**
     * Deletes the token and returns its instance's ID, or returns null when the
     * token is unknown or expired. Call inside a transaction, so a token can be
     * used only once even under concurrent requests.
     */
    public function consume(#[\SensitiveParameter] string $token): ?int
    {
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare('SELECT instance_id FROM clients_enrollment_tokens WHERE token_hash = ? AND expires_at > ?');
        $statement->execute([self::hash($token), $this->clock->now()]);
        $instanceId = $statement->fetchColumn();

        if ($instanceId === false) {
            return null;
        }

        $pdo->prepare('DELETE FROM clients_enrollment_tokens WHERE token_hash = ?')->execute([self::hash($token)]);

        return (int) $instanceId;
    }

    /**
     * @param int[] $instanceIds
     * @return int[] those of $instanceIds that have an unexpired token
     */
    public function pendingAmong(array $instanceIds): array
    {
        if ($instanceIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($instanceIds), '?'));
        $statement = $this->database->pdo()->prepare(
            "SELECT instance_id FROM clients_enrollment_tokens WHERE expires_at > ? AND instance_id IN ({$placeholders})",
        );
        $statement->execute([$this->clock->now(), ...array_values($instanceIds)]);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
