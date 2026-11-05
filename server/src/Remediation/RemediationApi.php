<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation;

use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Kernel\Events\EventOutbox;
use Maguari\Server\Kernel\Events\Subscriber;
use Maguari\Server\Monitoring\MonitoringApi;
use Maguari\Server\Monitoring\Exception\InvalidClientCheck;
use Maguari\Server\Remediation\Application\ActionDecisions;
use Maguari\Server\Remediation\Application\CheckEventSubscriber;
use Maguari\Server\Remediation\Application\CheckUnits;
use Maguari\Server\Remediation\Application\IncidentResolutions;
use Maguari\Server\Remediation\Application\MaintenanceEnd;
use Maguari\Server\Remediation\Domain\Action;
use Maguari\Server\Remediation\Domain\Incident;
use Maguari\Server\Remediation\Domain\InstanceIncidents;
use Maguari\Server\Remediation\Domain\InstanceRemediation;
use Maguari\Server\Remediation\Domain\Mode;
use Maguari\Server\Remediation\Domain\Reconciliation;
use Maguari\Server\Remediation\Exception\InvalidMaintenanceEnd;
use Maguari\Server\Remediation\Infrastructure\CheckUnitRepository;
use Maguari\Server\Remediation\Infrastructure\IncidentRepository;
use Maguari\Server\Remediation\Infrastructure\InstanceRemediationRepository;

/**
 * The Remediation context's public interface: incidents (design section
 * 7.4), opened and resolved from Monitoring's events and by the tick, and
 * what the escalation ladder decides for them (section 7.1). Instances are
 * referred to by their Fleet ID.
 */
final class RemediationApi
{
    /** How many resolved incidents an instance's page lists. */
    public const RESOLVED_SHOWN = 20;

    /** How many actions an instance's page lists. */
    public const ACTIONS_SHOWN = 20;

    /** How many actions one page of the Logs screen lists. */
    public const ACTIONS_PER_LOG_PAGE = 100;

    /**
     * Nothing can execute yet, so everything the ladder decides is dry-run
     * (design section 7.2 item 9). The switch comes with real restarts.
     */
    public const DRY_RUN = true;

    /**
     * Many instances failing at once (design sections 7.2 item 5 and 7.3):
     * at least this share of the picked instances, and at least
     * MASS_FAILURE_MIN_INSTANCES. Alert emails are summarized then (section
     * 13.3), and phase 3's circuit breaker will reuse it.
     */
    public const MASS_FAILURE_SHARE = 0.5;
    public const MASS_FAILURE_MIN_INSTANCES = 2;

    /**
     * Whether $failing of $picked instances is a mass failure.
     */
    public static function isMassFailure(int $failing, int $picked): bool
    {
        return $failing >= self::MASS_FAILURE_MIN_INSTANCES && $failing >= self::MASS_FAILURE_SHARE * $picked;
    }

    private readonly IncidentRepository $incidents;
    private readonly IncidentResolutions $resolutions;
    private readonly CheckEventSubscriber $subscriber;
    private readonly InstanceRemediationRepository $instances;
    private readonly CheckUnitRepository $checkUnits;
    private readonly ActionDecisions $decisions;
    private readonly CheckUnits $checkUnitRules;

    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
        MonitoringApi $monitoring,
    ) {
        $this->incidents = new IncidentRepository($database);
        $outbox = new EventOutbox($database);
        $this->instances = new InstanceRemediationRepository($database);
        $this->checkUnits = new CheckUnitRepository($database);
        $this->resolutions = new IncidentResolutions($database, $clock, $this->incidents, $outbox, $monitoring, $this->instances);
        $this->subscriber = new CheckEventSubscriber($this->incidents, $outbox, $this->instances, $this->checkUnits);
        $this->decisions = new ActionDecisions($database, $clock, $this->incidents, $this->instances, $this->checkUnits, $monitoring);
        $this->checkUnitRules = new CheckUnits($database, $this->checkUnits, $monitoring);
    }

    /**
     * What Remediation subscribes to, for the tick's event delivery.
     *
     * @return list<Subscriber>
     */
    public function subscribers(): array
    {
        return [$this->subscriber];
    }

    /**
     * @param ?int[] $instanceIds null for every instance
     * @return list<Incident> the open incidents of these instances, newest first
     */
    public function openIncidents(?array $instanceIds = null): array
    {
        return $instanceIds === null ? array_reverse($this->incidents->allOpen()) : $this->incidents->openAmong($instanceIds);
    }

    /**
     * The instance's open incidents and its latest resolved ones, for its page.
     */
    public function incidents(int $instanceId): InstanceIncidents
    {
        return new InstanceIncidents(
            $this->incidents->openAmong([$instanceId]),
            $this->incidents->resolvedFor($instanceId, self::RESOLVED_SHOWN),
        );
    }

    /**
     * Resolves the incidents whose check has passed for 5 minutes, writing
     * their events. Run by the tick.
     *
     * @return list<Incident>
     */
    public function resolveDue(): array
    {
        return $this->resolutions->resolveDue();
    }

    /**
     * The hourly reconciliation with Monitoring, when due. Run by the tick.
     */
    public function reconcileIfDue(): ?Reconciliation
    {
        return $this->resolutions->reconcileIfDue();
    }

    /**
     * What the escalation ladder decides now, instance by instance (design
     * section 7.1). Run by the tick.
     *
     * @return list<Action> the actions it decided
     */
    public function decideActions(): array
    {
        return $this->decisions->decide();
    }

    /**
     * The instance's mode and recent actions, for its page.
     */
    public function instanceRemediation(int $instanceId): InstanceRemediation
    {
        return $this->instances->find($instanceId, $this->clock->now());
    }

    public function isInMaintenance(int $instanceId): bool
    {
        return $this->instances->inMaintenance($instanceId, $this->clock->now());
    }

    public function setMode(int $instanceId, Mode $mode): void
    {
        $at = $this->clock->now();
        $this->database->transaction(fn () => $this->instances->save($this->instances->find($instanceId, $at)->withMode($mode, $at)));
    }

    /**
     * @param string $until as a datetime-local form field sends it, in UTC
     * @throws InvalidMaintenanceEnd with a sentence for the administrator
     */
    public function startMaintenance(int $instanceId, string $until): void
    {
        $at = $this->clock->now();
        $end = MaintenanceEnd::parse($until, $at);
        $this->database->transaction(fn () => $this->instances->save($this->instances->find($instanceId, $at)->withMaintenanceUntil($end)));
    }

    public function endMaintenance(int $instanceId): void
    {
        $at = $this->clock->now();
        $this->database->transaction(fn () => $this->instances->save($this->instances->find($instanceId, $at)->endMaintenance($at)));
    }

    /**
     * The units administrators associated with the instance's checks.
     *
     * @return array<string, string> by CheckUnitRepository::key()
     */
    public function checkUnits(int $instanceId): array
    {
        return $this->checkUnits->forInstance($instanceId);
    }

    public static function checkUnitKey(string $checkName, string $subject): string
    {
        return CheckUnitRepository::key($checkName, $subject);
    }

    /**
     * Associates a unit with a local HTTP or database check of the instance,
     * or removes the association when $unit is empty. A check that is gone
     * changes nothing.
     *
     * @throws InvalidClientCheck with a sentence for the administrator
     */
    public function setCheckUnit(int $instanceId, int $checkId, string $unit): void
    {
        $this->checkUnitRules->set($instanceId, $checkId, $unit);
    }

    /**
     * The instance's actions, newest first, for its page.
     *
     * @return list<Action>
     */
    public function actionsOf(int $instanceId): array
    {
        return $this->instances->actionsOf($instanceId, self::ACTIONS_SHOWN);
    }

    /**
     * Every instance's actions, newest first, for the Logs screen.
     *
     * @param ?int $before only actions with a lower ID
     * @return list<Action>
     */
    public function recentActions(?int $before = null): array
    {
        return $this->instances->recentActions(self::ACTIONS_PER_LOG_PAGE, $before);
    }
}
