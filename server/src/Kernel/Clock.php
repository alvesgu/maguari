<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel;

interface Clock
{
    /**
     * Current time as Unix seconds (UTC by definition).
     */
    public function now(): int;
}
