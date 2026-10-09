<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Kernel\Events;

use Maguari\Server\Kernel\Events\Event;
use Maguari\Server\Kernel\Events\Subscriber;

/**
 * Records the events of the types it handles and runs $onHandle for each,
 * inside the delivery transaction.
 */
final class RecordingSubscriber implements Subscriber
{
    /** @var list<Event> */
    public array $handled = [];

    /**
     * @param list<string> $types
     * @param ?\Closure(Event): void $onHandle
     */
    public function __construct(
        private readonly array $types,
        private readonly ?\Closure $onHandle = null,
    ) {
    }

    public function handles(string $type): bool
    {
        return in_array($type, $this->types, true);
    }

    public function handle(Event $event): void
    {
        $this->handled[] = $event;

        if ($this->onHandle !== null) {
            ($this->onHandle)($event);
        }
    }
}
