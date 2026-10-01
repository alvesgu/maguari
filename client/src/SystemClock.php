<?php

declare(strict_types=1);

namespace Maguari\Client;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }

    public function sleep(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }
}
