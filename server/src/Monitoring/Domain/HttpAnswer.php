<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * What one request of a remote HTTP check got: a status, or why there was none.
 */
final class HttpAnswer
{
    private function __construct(
        public readonly ?int $status,
        public readonly int $elapsedMs,
        public readonly ?HttpFailure $failure,
    ) {
    }

    public static function status(int $status, int $elapsedMs): self
    {
        return new self($status, $elapsedMs, null);
    }

    public static function failure(HttpFailure $failure): self
    {
        return new self(null, 0, $failure);
    }

    /**
     * 2xx and 3xx: redirects are not followed, and one (to a login page, for
     * example) means the app is up.
     */
    public function passes(): bool
    {
        return $this->status !== null && $this->status >= 200 && $this->status < 400;
    }
}
