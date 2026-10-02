<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring;

/**
 * A stored stretch of consecutive equal readings (design section 9.1).
 */
final class MetricRun
{
    public function __construct(
        public readonly int $id,
        public readonly int|float $value,
        public readonly int $startAt,
        public readonly int $endAt,
    ) {
    }
}
