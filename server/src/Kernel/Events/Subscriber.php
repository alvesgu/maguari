<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\Events;

/**
 * Reacts to delivered events. handle() runs inside the delivery transaction
 * (EventDelivery), so it only writes to the database, and anything it writes,
 * new events included, is committed together with the event being marked
 * delivered. Never send email or call the network from here.
 */
interface Subscriber
{
    public function handles(string $type): bool;

    public function handle(Event $event): void;
}
