<?php

declare(strict_types=1);

namespace Maguari\Server\Http;

use Maguari\Server\Kernel\Clock;
use RuntimeException;

/**
 * The current PHP session, as seen by controllers. SessionMiddleware starts it
 * and saves it. Timeouts are enforced here from timestamps kept in the session,
 * not left to PHP's garbage collection (design section 11.3).
 */
final class Session
{
    public const COOKIE_NAME = 'maguari_session';
    public const IDLE_TIMEOUT_SECONDS = 2 * 3600;
    public const ABSOLUTE_TIMEOUT_SECONDS = 12 * 3600;

    private bool $destroyed = false;

    public function __construct(
        private readonly Clock $clock,
    ) {
    }

    /**
     * Called once per request, right after the session starts. An expired
     * session keeps its ID but loses all its data, including CSRF tokens.
     */
    public function enforceTimeouts(): void
    {
        $now = $this->clock->now();
        $createdAt = $_SESSION['created_at'] ?? null;
        $lastSeenAt = $_SESSION['last_seen_at'] ?? null;

        if (
            !is_int($createdAt)
            || !is_int($lastSeenAt)
            || $now - $createdAt >= self::ABSOLUTE_TIMEOUT_SECONDS
            || $now - $lastSeenAt >= self::IDLE_TIMEOUT_SECONDS
        ) {
            $_SESSION = ['created_at' => $now, 'last_seen_at' => $now];

            return;
        }

        $_SESSION['last_seen_at'] = $now;
    }

    public function administratorId(): ?int
    {
        $id = $_SESSION['administrator_id'] ?? null;

        return is_int($id) ? $id : null;
    }

    /**
     * Starts a new session ID for the signed-in administrator (design section
     * 11.3) and restarts the absolute timeout.
     */
    public function signIn(int $administratorId): void
    {
        $this->regenerateId();
        $now = $this->clock->now();
        $_SESSION['administrator_id'] = $administratorId;
        $_SESSION['created_at'] = $now;
        $_SESSION['last_seen_at'] = $now;
    }

    public function destroy(): void
    {
        $_SESSION = [];
        $this->destroyed = true;
    }

    public function isDestroyed(): bool
    {
        return $this->destroyed;
    }

    /**
     * Same effect as session_regenerate_id(true), which PHP refuses once any
     * output has been sent (as in PHPUnit). With use_cookies off, destroying and
     * starting a new session has no such restriction.
     */
    private function regenerateId(): void
    {
        $data = $_SESSION;
        session_destroy();
        $newId = session_create_id();

        if ($newId === false || session_id($newId) === false || !session_start()) {
            throw new RuntimeException('Could not regenerate the session ID.');
        }

        $_SESSION = $data;
    }
}
