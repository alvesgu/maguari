<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation;

use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Kernel\Events\EventOutbox;
use Maguari\Server\Kernel\Events\Subscriber;
use Maguari\Server\Monitoring\MonitoringApi;
use Maguari\Server\Remediation\Application\CheckEventSubscriber;
use Maguari\Server\Remediation\Application\IncidentResolutions;
use Maguari\Server\Remediation\Domain\Incident;
use Maguari\Server\Remediation\Domain\InstanceIncidents;
use Maguari\Server\Remediation\Domain\Reconciliation;
use Maguari\Server\Remediation\Infrastructure\IncidentRepository;

/**
 * The Remediation context's public interface: incidents (design section
 * 7.4), opened and resolved from Monitoring's events and by the tick.
 * Instances are referred to by their Fleet ID.
 */
final class RemediationApi
{
    /** How many resolved incidents an instance's page lists. */
    public const RESOLVED_SHOWN = 20;

    private readonly IncidentRepository $incidents;
    private readonly IncidentResolutions $resolutions;
    private readonly CheckEventSubscriber $subscriber;

    public function __construct(
        Database $database,
        Clock $clock,
        MonitoringApi $monitoring,
    ) {
        $this->incidents = new IncidentRepository($database);
        $outbox = new EventOutbox($database);
        $this->resolutions = new IncidentResolutions($database, $clock, $this->incidents, $outbox, $monitoring);
        $this->subscriber = new CheckEventSubscriber($this->incidents, $outbox);
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
     * The daily reconciliation with Monitoring, when due. Run by the tick.
     */
    public function reconcileIfDue(): ?Reconciliation
    {
        return $this->resolutions->reconcileIfDue();
    }
}
