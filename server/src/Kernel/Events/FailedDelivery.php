<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\Events;

/**
 * An event whose subscribers threw. Its delivery was rolled back.
 */
final class FailedDelivery
{
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly \Throwable $exception,
        /** How many ticks it has failed on, this one included. */
        public readonly int $failedAttempts,
        /** Whether it is now skipped for good. */
        public readonly bool $skipped,
    ) {
    }
}
