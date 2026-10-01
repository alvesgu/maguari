<?php

declare(strict_types=1);

namespace Maguari\Client\Tests\Support;

use Maguari\Client\Clock;

/**
 * Time stands still unless advanced; sleeping advances it and is recorded.
 */
final class FakeClock implements Clock
{
    /** @var list<int> */
    public array $sleeps = [];

    public function __construct(
        public int $now = 1_790_000_000,
    ) {
    }

    public function now(): int
    {
        return $this->now;
    }

    public function sleep(int $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->now += max(0, $seconds);
    }
}
