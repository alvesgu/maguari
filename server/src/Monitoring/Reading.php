<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring;

/**
 * One validated reading of a known metric.
 */
final class Reading
{
    /**
     * @param int $deadband how far the value may move from the run's value
     *                      before a new run starts; 0 means exact equality
     *                      (design section 9.1)
     */
    public function __construct(
        public readonly string $metric,
        public readonly int $value,
        public readonly int $deadband = 0,
    ) {
    }
}
