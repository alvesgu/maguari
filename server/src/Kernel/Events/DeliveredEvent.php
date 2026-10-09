<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\Events;

final class DeliveredEvent
{
    public function __construct(
        public readonly int $id,
        public readonly string $type,
    ) {
    }
}
