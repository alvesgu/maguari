<?php

declare(strict_types=1);

namespace Maguari\Server\Access;

use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;

/**
 * Limits failed login and setup submissions per IP address (design section 11.3).
 */
final class LoginThrottle
{
    public const MAX_FAILURES = 10;
    public const WINDOW_SECONDS = 900;

    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
    ) {
    }

    public function isLimited(string $ip): bool
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT COUNT(*) FROM access_login_attempts WHERE ip = ? AND attempted_at > ?',
        );
        $statement->execute([$ip, $this->clock->now() - self::WINDOW_SECONDS]);

        return (int) $statement->fetchColumn() >= self::MAX_FAILURES;
    }

    public function recordFailure(string $ip): void
    {
        $now = $this->clock->now();
        $pdo = $this->database->pdo();
        $pdo->prepare('DELETE FROM access_login_attempts WHERE attempted_at <= ?')
            ->execute([$now - self::WINDOW_SECONDS]);
        $pdo->prepare('INSERT INTO access_login_attempts (ip, attempted_at) VALUES (?, ?)')
            ->execute([$ip, $now]);
    }
}
