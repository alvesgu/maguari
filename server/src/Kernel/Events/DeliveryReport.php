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

    /**
     * Whether delivery stopped at a failing event, which waits for the next
     * tick: delivering again in the same tick would count a second failure.
     */
    public function stopped(): bool
    {
        $last = $this->failures[count($this->failures) - 1] ?? null;

        return $last !== null && !$last->skipped;
    }
}
