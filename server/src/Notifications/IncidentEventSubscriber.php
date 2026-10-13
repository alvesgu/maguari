<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Events\Event;
use Maguari\Server\Kernel\Events\Subscriber;
use Maguari\Server\Remediation\Event\IncidentOpened;
use Maguari\Server\Remediation\Event\IncidentResolved;

/**
 * Keeps one notification per incident from Remediation's events (design
 * section 10.4), with a pending alert email when it opens and when it
 * resolves (section 13.3). Runs inside the delivery transaction; the tick
 * sends the alerts later, outside it.
 */
final class IncidentEventSubscriber implements Subscriber
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly AlertRepository $alerts,
        private readonly Clock $clock,
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
            $id = $this->notifications->add($opened->incidentId, $opened->instanceId, $opened->checkName, $opened->subject, $opened->at, $opened->detail);

            if ($id !== null) {
                $this->alerts->add($id, AlertKind::Opened, $this->clock->now(), $opened->detectedLate);
            }
        } elseif ($event->type === IncidentResolved::TYPE) {
            // An incident opened before Notifications subscribed has no
            // notification to resolve.
            $resolved = IncidentResolved::fromEvent($event);
            $id = $this->notifications->resolve($resolved->incidentId, $resolved->at, $resolved->resolution->value);

            if ($id !== null) {
                $this->alerts->add($id, AlertKind::Resolved, $this->clock->now());
            }
        }
    }
}
