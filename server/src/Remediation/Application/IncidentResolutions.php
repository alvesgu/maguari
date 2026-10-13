<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Application;

use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Kernel\Events\EventOutbox;
use Maguari\Server\Monitoring\MonitoringApi;
use Maguari\Server\Remediation\Domain\Incident;
use Maguari\Server\Remediation\Domain\IncidentKey;
use Maguari\Server\Remediation\Domain\Reconciliation;
use Maguari\Server\Remediation\Domain\ResolutionRule;
use Maguari\Server\Remediation\Event\IncidentOpened;
use Maguari\Server\Remediation\Event\IncidentResolved;
use Maguari\Server\Remediation\Infrastructure\IncidentRepository;

/**
 * The tick's Remediation step (design section 10.1): resolves incidents whose
 * check has passed long enough and, once a day, reconciles open incidents
 * with Monitoring's current outcomes (design section 7.4).
 */
final class IncidentResolutions
{
    public const RECONCILE_EVERY_SECONDS = 86_400;

    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
        private readonly IncidentRepository $incidents,
        private readonly EventOutbox $outbox,
        private readonly MonitoringApi $monitoring,
    ) {
    }

    /**
     * @return list<Incident> the incidents resolved, oldest first
     */
    public function resolveDue(): array
    {
        $at = $this->clock->now();

        return $this->database->transaction(function () use ($at): array {
            $resolved = [];

            foreach ($this->incidents->openPassingSince($at - ResolutionRule::RESOLVE_AFTER_SECONDS) as $incident) {
                $after = $incident->resolveIfDue($at);

                if ($after !== null) {
                    $resolved[] = $this->incidents->save($after);
                    $this->outbox->record(IncidentResolved::of($after)->toEvent());
                }
            }

            return $resolved;
        });
    }

    /**
     * Compares open incidents with Monitoring's failing checks, when the last
     * comparison is more than a day old (or never ran), so an event lost to a
     * bug, or written before Remediation subscribed, cannot leave the two
     * disagreeing for good.
     *
     * @return ?Reconciliation null when not due
     */
    public function reconcileIfDue(): ?Reconciliation
    {
        $at = $this->clock->now();

        return $this->database->transaction(function () use ($at): ?Reconciliation {
            $last = $this->incidents->lastReconciledAt();

            if ($last !== null && $at - $last <= self::RECONCILE_EVERY_SECONDS) {
                return null;
            }

            $open = $this->incidents->allOpen();
            $failingKeys = [];
            $opened = [];
            $resolved = [];

            foreach ($this->monitoring->failingChecks() as $state) {
                $key = new IncidentKey($state->instanceId, $state->checkName, $state->subject);
                $failingKeys[] = $key;
                $incident = self::find($open, $key);

                if ($incident === null) {
                    // Opened as check_failed would have, but without the sentence.
                    $incident = $this->incidents->save(Incident::open($key, $state->since));
                    $this->outbox->record(IncidentOpened::of($incident, '')->toEvent());
                    $opened[] = $incident;
                } elseif ($incident->passingSince !== null) {
                    // A lost check_failed: it is failing, not passing.
                    $this->incidents->save($incident->failedAgain());
                }
            }

            foreach ($open as $incident) {
                if (self::contains($failingKeys, $incident->key)) {
                    continue;
                }

                $key = $incident->key;
                $after = $this->incidents->save($incident->reconcile($this->monitoring->passingSince($key->instanceId, $key->checkName, $key->subject), $at));

                if (!$after->isOpen()) {
                    $this->outbox->record(IncidentResolved::of($after)->toEvent());
                    $resolved[] = $after;
                }
            }

            $this->incidents->recordReconciliation($at, count($opened), count($resolved));

            return new Reconciliation($at, $opened, $resolved);
        });
    }

    /**
     * @param list<Incident> $incidents
     */
    private static function find(array $incidents, IncidentKey $key): ?Incident
    {
        foreach ($incidents as $incident) {
            if ($incident->key->equals($key)) {
                return $incident;
            }
        }

        return null;
    }

    /**
     * @param list<IncidentKey> $keys
     */
    private static function contains(array $keys, IncidentKey $key): bool
    {
        foreach ($keys as $known) {
            if ($known->equals($key)) {
                return true;
            }
        }

        return false;
    }
}
