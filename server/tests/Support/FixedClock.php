<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Support;

use Maguari\Server\Kernel\Clock;

final class FixedClock implements Clock
{
    public function __construct(
        private int $now = 1_790_000_000,
    ) {
    }

    public function now(): int
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}
