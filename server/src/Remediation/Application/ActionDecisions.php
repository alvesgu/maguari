<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Application;

use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Monitoring\Domain\CheckState;
use Maguari\Server\Monitoring\Domain\HeartbeatState;
use Maguari\Server\Monitoring\MonitoringApi;
use Maguari\Server\Remediation\Domain\Action;
use Maguari\Server\Remediation\Domain\Incident;
use Maguari\Server\Remediation\Domain\IncidentSituation;
use Maguari\Server\Remediation\Domain\Ladder;
use Maguari\Server\Remediation\Infrastructure\CheckUnitRepository;
use Maguari\Server\Remediation\Infrastructure\IncidentRepository;
use Maguari\Server\Remediation\Infrastructure\InstanceRemediationRepository;
use Maguari\Server\Remediation\RemediationApi;

/**
 * The tick's step that climbs the escalation ladder (design section 7.1). A
 * tick step, not an event subscriber: the waits pass without any event, and
 * events are also delivered inside web requests. Each instance gets one
 * transaction, which changes both aggregates (section 3.1 item 7).
 */
final class ActionDecisions
{
    /** No new instance after this, so the alert emails still get their time. */
    public const TIME_LIMIT_SECONDS = 20;

    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
        private readonly IncidentRepository $incidents,
        private readonly InstanceRemediationRepository $instances,
        private readonly CheckUnitRepository $checkUnits,
        private readonly MonitoringApi $monitoring,
    ) {
    }

    /**
     * @return list<Action> the actions decided, as stored
     */
    public function decide(): array
    {
        $started = $this->clock->now();
        $instanceIds = $this->instances->automaticWithOpenIncidents();
        $failing = $this->failingByKey();
        $heartbeats = $this->monitoring->heartbeatStatuses($instanceIds);
        $decided = [];

        foreach ($instanceIds as $instanceId) {
            $at = $this->clock->now();

            if ($at - $started >= self::TIME_LIMIT_SECONDS) {
                break;
            }

            $heartbeatOnTime = ($heartbeats[$instanceId]->state ?? null) === HeartbeatState::OnTime;
            $decided = [...$decided, ...$this->database->transaction(fn (): array => $this->decideFor($instanceId, $heartbeatOnTime, $failing, $at))];
        }

        return $decided;
    }

    /**
     * @param array<string, CheckState> $failing
     * @return list<Action>
     */
    private function decideFor(int $instanceId, bool $heartbeatOnTime, array $failing, int $at): array
    {
        $units = $this->checkUnits->forInstance($instanceId);
        $situations = array_map(
            static function (Incident $incident) use ($units, $failing): IncidentSituation {
                $key = $incident->key;
                $state = $failing[self::key($key->instanceId, $key->checkName, $key->subject)] ?? null;
                $unit = Ladder::unit($key->checkName, $key->subject, $units[CheckUnitRepository::key($key->checkName, $key->subject)] ?? null);

                return new IncidentSituation(
                    $incident,
                    $state !== null && $incident->passingSince === null,
                    Ladder::path($key->checkName, $unit, $state?->reason),
                    $unit,
                    $state?->checkedAt,
                );
            },
            array_reverse($this->incidents->openAmong([$instanceId])),
        );
        $decision = $this->instances->find($instanceId, $at)->decide($situations, $heartbeatOnTime, RemediationApi::DRY_RUN, $at);

        foreach ($decision->changedActions as $action) {
            $this->instances->saveAction($action);
        }

        foreach ($decision->incidents as $incident) {
            $this->incidents->save($incident);
        }

        return array_map($this->instances->saveAction(...), $decision->newActions);
    }

    /**
     * @return array<string, CheckState>
     */
    private function failingByKey(): array
    {
        $failing = [];

        foreach ($this->monitoring->failingChecks() as $state) {
            $failing[self::key($state->instanceId, $state->checkName, $state->subject)] = $state;
        }

        return $failing;
    }

    private static function key(int $instanceId, string $checkName, string $subject): string
    {
        return $instanceId . "\n" . $checkName . "\n" . $subject;
    }
}
