<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

use Maguari\Server\Kernel\Events\Event;
use Maguari\Server\Kernel\Events\Subscriber;
use Maguari\Server\Remediation\Event\IncidentOpened;
use Maguari\Server\Remediation\Event\IncidentResolved;

/**
 * Keeps one notification per incident from Remediation's events (design
 * section 10.4). Runs inside the delivery transaction.
 */
final class IncidentEventSubscriber implements Subscriber
{
    public function __construct(
        private readonly NotificationRepository $notifications,
    ) {
    }

    public function handles(string $type): bool
    {
        return in_array($type, [IncidentOpened::TYPE, IncidentResolved::TYPE], true);
    }

    public function handle(Event $event): void
    {
        if ($event->type === IncidentOpened::TYPE) {
            $opened = IncidentOpened::fromEvent($event);
            $this->notifications->add($opened->incidentId, $opened->instanceId, $opened->checkName, $opened->subject, $opened->at);
        } elseif ($event->type === IncidentResolved::TYPE) {
            // An incident opened before Notifications subscribed has no
            // notification to resolve.
            $resolved = IncidentResolved::fromEvent($event);
            $this->notifications->resolve($resolved->incidentId, $resolved->at);
        }
    }
}
