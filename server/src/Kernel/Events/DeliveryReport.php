<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\Events;

/**
 * What one call of EventDelivery::deliver() did.
 */
final class DeliveryReport
{
    /**
     * @param list<DeliveredEvent> $delivered in delivery order
     * @param list<FailedDelivery> $failures in order. Only the last one can be
     *        unskipped: delivery stops there until the next tick.
     * @param bool $limitReached whether events may still be pending
     */
    public function __construct(
        public readonly array $delivered,
        public readonly array $failures,
        public readonly bool $limitReached,
    ) {
    }
}
