<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\HttpProbe;

final class HttpProbeAnswer
{
    /**
     * @param int $elapsedMs from the start of the connection to the status line
     */
    public function __construct(
        public readonly int $status,
        public readonly int $elapsedMs,
    ) {
    }
}
