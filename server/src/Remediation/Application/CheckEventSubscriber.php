<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Application;

use Maguari\Server\Kernel\Events\Event;
use Maguari\Server\Kernel\Events\EventOutbox;
use Maguari\Server\Kernel\Events\Subscriber;
use Maguari\Server\Monitoring\Event\CheckFailed;
use Maguari\Server\Monitoring\Event\CheckPassed;
use Maguari\Server\Monitoring\Event\CheckWithdrawn;
use Maguari\Server\Remediation\Domain\Incident;
use Maguari\Server\Remediation\Domain\IncidentKey;
use Maguari\Server\Remediation\Event\IncidentOpened;
use Maguari\Server\Remediation\Event\IncidentResolved;
use Maguari\Server\Remediation\Infrastructure\IncidentRepository;

/**
 * Opens and updates incidents from Monitoring's outcome changes (design
 * section 7.4). Runs inside the delivery transaction, so the incident and
 * the event it writes are committed together with the delivery.
 */
final class CheckEventSubscriber implements Subscriber
{
    public function __construct(
        private readonly IncidentRepository $incidents,
        private readonly EventOutbox $outbox,
    ) {
    }

    public function handles(string $type): bool
    {
        return in_array($type, [CheckFailed::TYPE, CheckPassed::TYPE, CheckWithdrawn::TYPE], true);
    }

    public function handle(Event $event): void
    {
        match ($event->type) {
            CheckFailed::TYPE => $this->failed(CheckFailed::fromEvent($event)),
            CheckPassed::TYPE => $this->passed(CheckPassed::fromEvent($event)),
            CheckWithdrawn::TYPE => $this->withdrawn(CheckWithdrawn::fromEvent($event)),
            default => null,
        };
    }

    /**
     * Opens an incident, or restarts the wait of the open one.
     */
    private function failed(CheckFailed $failed): void
    {
        $key = new IncidentKey($failed->instanceId, $failed->checkName, $failed->subject);
        $open = $this->incidents->openFor($key);

        if ($open !== null) {
            $this->incidents->save($open->failedAgain());

            return;
        }

        $incident = $this->incidents->save(Incident::open($key, $failed->at));
        $this->outbox->record(IncidentOpened::of($incident, $failed->detail)->toEvent());
    }

    /**
     * Starts the wait of the open incident, if any. The tick resolves it
     * once the wait is over (RemediationApi::resolveDue()).
     */
    private function passed(CheckPassed $passed): void
    {
        $open = $this->incidents->openFor(new IncidentKey($passed->instanceId, $passed->checkName, $passed->subject));

        if ($open !== null) {
            $this->incidents->save($open->passed($passed->at));
        }
    }

    /**
     * Resolves the open incident at once: its subject is gone.
     */
    private function withdrawn(CheckWithdrawn $withdrawn): void
    {
        $open = $this->incidents->openFor(new IncidentKey($withdrawn->instanceId, $withdrawn->checkName, $withdrawn->subject));

        if ($open !== null) {
            $resolved = $this->incidents->save($open->withdraw($withdrawn->at));
            $this->outbox->record(IncidentResolved::of($resolved)->toEvent());
        }
    }
}
