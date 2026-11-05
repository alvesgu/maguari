<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Remediation\Domain;

use Maguari\Server\Monitoring\Domain\HeartbeatAgeRule;
use Maguari\Server\Remediation\Domain\Action;
use Maguari\Server\Remediation\Domain\ActionKind;
use Maguari\Server\Remediation\Domain\Decision;
use Maguari\Server\Remediation\Domain\Incident;
use Maguari\Server\Remediation\Domain\IncidentKey;
use Maguari\Server\Remediation\Domain\IncidentSituation;
use Maguari\Server\Remediation\Domain\InstanceRemediation;
use Maguari\Server\Remediation\Domain\Ladder;
use Maguari\Server\Remediation\Domain\LadderHold;
use Maguari\Server\Remediation\Domain\LadderPath;
use Maguari\Server\Remediation\Domain\Mode;
use PHPUnit\Framework\TestCase;

/**
 * The escalation ladder's rules (design section 7.1), with plain numbers.
 */
final class InstanceRemediationTest extends TestCase
{
    private const OPENED = 1_000_000;
    private const NGINX = 'nginx.service';

    /**
     * @param list<Action> $actions
     */
    private static function instance(array $actions = [], ?int $maintenanceUntil = null): InstanceRemediation
    {
        return new InstanceRemediation(7, Mode::Automatic, 0, $maintenanceUntil, $actions);
    }

    private static function service(int $id = 1, int $step = 0, ?int $lastResultAt = null, string $unit = self::NGINX, int $opened = self::OPENED, ?int $passingSince = null): IncidentSituation
    {
        $incident = new Incident($id, new IncidentKey(7, 'service', $unit), $opened, $passingSince, null, null, $step);

        return new IncidentSituation($incident, $passingSince === null, LadderPath::Full, $unit, $lastResultAt);
    }

    private static function silent(int $id = 2, int $step = 0): IncidentSituation
    {
        $incident = new Incident($id, new IncidentKey(7, Ladder::HEARTBEAT_AGE, ''), self::OPENED, null, null, null, $step);

        return new IncidentSituation($incident, true, LadderPath::ResetOnly);
    }

    /**
     * @param list<int> $incidentIds
     */
    private static function action(int $id, ActionKind $kind, int $at, array $incidentIds = [1], ?string $unit = self::NGINX): Action
    {
        return Action::wouldRun(7, $kind, $kind === ActionKind::Restart ? $unit : null, $at, $incidentIds)->withId($id);
    }

    /**
     * @return list<string> the new actions' sentences
     */
    private static function sentences(Decision $decision): array
    {
        return array_map(static fn (Action $action): string => $action->sentence(), $decision->newActions);
    }

    private static function step(Decision $decision, int $incidentId): ?int
    {
        foreach ($decision->incidents as $incident) {
            if ($incident->id === $incidentId) {
                return $incident->ladderStep;
            }
        }

        return null;
    }

    public function testActsOnlyAfterThreeMinutesOpen(): void
    {
        $this->assertSame([], self::instance()->decide([self::service()], true, true, self::OPENED + 179)->newActions);

        $decision = self::instance()->decide([self::service()], true, true, self::OPENED + 180);

        $this->assertSame(['I would restart nginx.service now.'], self::sentences($decision));
        $this->assertSame(Ladder::STEP_RESTART, self::step($decision, 1));
        $this->assertTrue($decision->newActions[0]->dryRun);
        $this->assertSame([1], $decision->newActions[0]->incidentIds);
    }

    public function testNothingWhilePassing(): void
    {
        $this->assertSame([], self::instance()->decide([self::service(passingSince: self::OPENED + 100)], true, true, self::OPENED + 180)->newActions);
    }

    public function testNothingInAlertOnlyOrInMaintenance(): void
    {
        $alertOnly = InstanceRemediation::newlyPicked(7);
        $this->assertSame(Mode::AlertOnly, $alertOnly->mode);
        $this->assertSame([], $alertOnly->decide([self::service()], true, true, self::OPENED + 180)->newActions);

        $inMaintenance = self::instance([], self::OPENED + 1000);
        $this->assertTrue($inMaintenance->isInMaintenance(self::OPENED + 180));
        $this->assertSame([], $inMaintenance->decide([self::service()], true, true, self::OPENED + 180)->newActions);
    }

    public function testTurningAutomaticAndEndingMaintenanceStartTheWaitOver(): void
    {
        $turned = InstanceRemediation::newlyPicked(7)->withMode(Mode::Automatic, self::OPENED + 500);
        $this->assertSame([], $turned->decide([self::service()], true, true, self::OPENED + 679)->newActions);
        $this->assertCount(1, $turned->decide([self::service()], true, true, self::OPENED + 680)->newActions);

        $ended = self::instance([], self::OPENED + 900)->endMaintenance(self::OPENED + 500);
        $this->assertSame(self::OPENED + 500, $ended->maintenanceUntil);
        $this->assertSame([], $ended->decide([self::service()], true, true, self::OPENED + 679)->newActions);
        $this->assertCount(1, $ended->decide([self::service()], true, true, self::OPENED + 680)->newActions);
    }

    public function testSettingTheSameModeDoesNotStartTheWaitOver(): void
    {
        $instance = self::instance();

        $this->assertSame($instance, $instance->withMode(Mode::Automatic, self::OPENED + 500));
    }

    public function testAnAlertOnlyPathNeverActs(): void
    {
        $incident = new Incident(1, new IncidentKey(7, 'remote_http', 'https://example.com/'), self::OPENED);
        $situation = new IncidentSituation($incident, true, LadderPath::AlertOnly);

        $this->assertSame([], self::instance()->decide([$situation], true, true, self::OPENED + 100_000)->newActions);
    }

    public function testRestartAndRebootNeedTheClient(): void
    {
        $restart = self::action(1, ActionKind::Restart, self::OPENED + 180);
        $again = self::action(2, ActionKind::Restart, self::OPENED + 400);

        $this->assertSame([], self::instance()->decide([self::service()], false, true, self::OPENED + 180)->newActions);
        $this->assertSame([], self::instance([$restart, $again])->decide([self::service(step: 2, lastResultAt: self::OPENED + 500)], false, true, self::OPENED + 600)->newActions);
    }

    public function testTheLadderGoesOnOnlyWithAResultNewerThanTheRestartsEnd(): void
    {
        $at = self::OPENED + 180;
        $restart = self::action(1, ActionKind::Restart, $at);
        $instance = self::instance([$restart]);

        $this->assertSame([], $instance->decide([self::service(step: 1, lastResultAt: null)], true, true, $at + 60)->newActions);
        // The result in the heartbeat that brought the outcome does not count.
        $this->assertSame([], $instance->decide([self::service(step: 1, lastResultAt: $at)], true, true, $at + 60)->newActions);

        $decision = $instance->decide([self::service(step: 1, lastResultAt: $at + 1)], true, true, $at + 60);
        $this->assertSame(['I would restart nginx.service now.'], self::sentences($decision));
        $this->assertSame(Ladder::STEP_RESTART_AGAIN, self::step($decision, 1));
    }

    public function testTheSecondRestartLeadsToTheReboot(): void
    {
        $at = self::OPENED + 300;
        $instance = self::instance([self::action(1, ActionKind::Restart, $at - 120), self::action(2, ActionKind::Restart, $at)]);

        $this->assertSame([], $instance->decide([self::service(step: 2, lastResultAt: $at)], true, true, $at + 60)->newActions);

        $decision = $instance->decide([self::service(step: 2, lastResultAt: $at + 1)], true, true, $at + 60);
        $this->assertSame(['I would reboot the instance now.'], self::sentences($decision));
        $this->assertSame(Ladder::STEP_REBOOT, self::step($decision, 1));
    }

    public function testBootGraceThenResetThenBootGraceThenExhausted(): void
    {
        $reboot = self::OPENED + 500;
        $afterReboot = self::instance([self::action(1, ActionKind::Reboot, $reboot)]);

        $this->assertSame([], $afterReboot->decide([self::service(step: 3)], true, true, $reboot + 419)->newActions);

        $decision = $afterReboot->decide([self::service(step: 3)], true, true, $reboot + 420);
        $this->assertSame(['I would reset the instance now.'], self::sentences($decision));
        $this->assertSame(Ladder::STEP_RESET, self::step($decision, 1));

        $reset = $reboot + 420;
        $afterReset = self::instance([self::action(1, ActionKind::Reboot, $reboot), self::action(2, ActionKind::Reset, $reset)]);
        $this->assertSame([], $afterReset->decide([self::service(step: 4)], true, true, $reset + 419)->incidents);

        $decision = $afterReset->decide([self::service(step: 4)], true, true, $reset + 420);
        $this->assertSame([], $decision->newActions);
        $this->assertSame($reset + 420, $decision->incidents[0]->exhaustedAt);
    }

    public function testAnExhaustedIncidentDoesNothingMore(): void
    {
        $incident = new Incident(1, new IncidentKey(7, 'service', self::NGINX), self::OPENED, null, null, null, 4, self::OPENED + 900);
        $situation = new IncidentSituation($incident, true, LadderPath::Full, self::NGINX, self::OPENED + 1000);
        $decision = self::instance()->decide([$situation], true, true, self::OPENED + 100_000);

        $this->assertSame([], $decision->newActions);
        $this->assertSame([], $decision->incidents);
    }

    public function testASilentInstanceGoesStraightToTheResetWithoutItsClient(): void
    {
        $decision = self::instance()->decide([self::silent()], false, true, self::OPENED + 180);

        $this->assertSame(['I would reset the instance now.'], self::sentences($decision));
        $this->assertSame(Ladder::STEP_RESET, self::step($decision, 2));
    }

    public function testASilentInstanceGetsOneResetPerIncident(): void
    {
        $reset = self::OPENED + 180;
        $decision = self::instance([self::action(1, ActionKind::Reset, $reset, [2])])->decide([self::silent(step: 4)], false, true, $reset + 420);

        $this->assertSame([], $decision->newActions);
        $this->assertSame($reset + 420, $decision->incidents[0]->exhaustedAt);
    }

    public function testTwoIncidentsAskingForTheSameRestartGetOne(): void
    {
        $decision = self::instance()->decide([self::service(1), self::service(2)], true, true, self::OPENED + 180);

        $this->assertCount(1, $decision->newActions);
        $this->assertSame([1, 2], $decision->newActions[0]->incidentIds);
        $this->assertSame(Ladder::STEP_RESTART, self::step($decision, 1));
        $this->assertSame(Ladder::STEP_RESTART, self::step($decision, 2));
    }

    public function testALaterIncidentJoinsARestartThatJustHappened(): void
    {
        $restart = self::OPENED + 180;
        $instance = self::instance([self::action(1, ActionKind::Restart, $restart, [1])]);
        $later = self::service(2, opened: $restart - 180);

        $decision = $instance->decide([$later], true, true, $restart + 299);
        $this->assertSame([], $decision->newActions);
        $this->assertSame([1, 2], $decision->changedActions[0]->incidentIds);
        $this->assertSame(Ladder::STEP_RESTART, self::step($decision, 2));

        // Past the cooldown it asks for its own.
        $decision = $instance->decide([$later], true, true, $restart + 300);
        $this->assertCount(1, $decision->newActions);
        $this->assertSame([], $decision->changedActions);
    }

    public function testTheCooldownDoesNotSpaceOutOneIncidentsOwnLadder(): void
    {
        $restart = self::OPENED + 180;
        $instance = self::instance([self::action(1, ActionKind::Restart, $restart)]);

        $this->assertCount(1, $instance->decide([self::service(step: 1, lastResultAt: $restart + 60)], true, true, $restart + 60)->newActions);
    }

    public function testAnOlderRestartOfAnotherIncidentIsNotTakenForANewOne(): void
    {
        $at = self::OPENED + 180;
        $instance = self::instance([self::action(1, ActionKind::Restart, $at, [2]), self::action(2, ActionKind::Restart, $at + 60, [1])]);

        // Its own restart is the latest, so it asks for the next, not the older one.
        $this->assertCount(1, $instance->decide([self::service(1, step: 1, lastResultAt: $at + 120)], true, true, $at + 120)->newActions);
    }

    public function testDifferentUnitsDoNotHoldEachOtherUp(): void
    {
        $decision = self::instance()->decide([self::service(1), self::service(2, unit: 'php8.3-fpm.service')], true, true, self::OPENED + 180);

        $this->assertSame(['I would restart nginx.service now.', 'I would restart php8.3-fpm.service now.'], self::sentences($decision));
    }

    public function testTheRestartLimitHoldsTheLadderWithoutClimbing(): void
    {
        $at = self::OPENED + 180;
        $actions = [
            self::action(1, ActionKind::Restart, $at - 3000, [9], 'a.service'),
            self::action(2, ActionKind::Restart, $at - 2000, [9], 'b.service'),
            self::action(3, ActionKind::Restart, $at - 1000, [9], 'c.service'),
        ];
        $decision = self::instance($actions)->decide([self::service()], true, true, $at);

        $this->assertSame([], $decision->newActions);
        $this->assertSame(LadderHold::RestartLimit, $decision->incidents[0]->ladderHold);
        $this->assertSame(0, $decision->incidents[0]->ladderStep);

        // An hour after the first, another is allowed and the hold ends.
        $decision = self::instance($actions)->decide([self::service()], true, true, $at - 3000 + 3600);
        $this->assertCount(1, $decision->newActions);
        $this->assertNull($decision->incidents[0]->ladderHold);
    }

    public function testTheRebootLimitExhaustsTheLadderWithoutAReset(): void
    {
        $at = self::OPENED + 60_000;
        $actions = [
            self::action(1, ActionKind::Reboot, $at - 50_000, [9]),
            self::action(2, ActionKind::Reboot, $at - 40_000, [9]),
            self::action(3, ActionKind::Reboot, $at - 30_000, [9]),
            self::action(4, ActionKind::Restart, $at - 300),
            self::action(5, ActionKind::Restart, $at - 120),
        ];
        $decision = self::instance($actions)->decide([self::service(step: 2, lastResultAt: $at - 60)], true, true, $at);

        $this->assertSame([], $decision->newActions);
        $this->assertSame($at, $decision->incidents[0]->exhaustedAt);
    }

    public function testTheResetLimitCountsAcrossIncidents(): void
    {
        $at = self::OPENED + 60_000;
        $actions = [
            self::action(1, ActionKind::Reset, $at - 50_000, [7]),
            self::action(2, ActionKind::Reset, $at - 40_000, [8]),
            self::action(3, ActionKind::Reset, $at - 30_000, [9]),
        ];
        $decision = self::instance($actions)->decide([self::silent()], false, true, $at);

        $this->assertSame([], $decision->newActions);
        $this->assertSame(LadderHold::ResetLimit, $decision->incidents[0]->ladderHold);
        $this->assertNull($decision->incidents[0]->exhaustedAt);

        $this->assertCount(1, self::instance($actions)->decide([self::silent()], false, true, $at - 50_000 + 86_400)->newActions);
    }

    public function testTheResetCooldownSeparatesResetsOfDifferentIncidents(): void
    {
        $reset = self::OPENED;
        $instance = self::instance([self::action(1, ActionKind::Reset, $reset, [9])]);
        $later = new IncidentSituation(new Incident(2, new IncidentKey(7, Ladder::HEARTBEAT_AGE, ''), $reset), true, LadderPath::ResetOnly);

        $this->assertSame([], $instance->decide([$later], false, true, $reset + 599)->newActions);
        $this->assertCount(1, $instance->decide([$later], false, true, $reset + 600)->newActions);
    }

    public function testAnInstanceWideActionServesEveryIncidentThatMayUseIt(): void
    {
        $at = self::OPENED + 180;
        $alertOnly = new IncidentSituation(new Incident(3, new IncidentKey(7, 'remote_http', 'https://example.com/'), self::OPENED), true, LadderPath::AlertOnly);
        $decision = self::instance()->decide([self::silent(2), self::service(1), $alertOnly], true, true, $at);

        $this->assertSame(['I would reset the instance now.'], self::sentences($decision));
        $this->assertSame([2, 1], $decision->newActions[0]->incidentIds);
        $this->assertSame(Ladder::STEP_RESET, self::step($decision, 1));
        $this->assertNull(self::step($decision, 3));
    }

    public function testARebootWaitsForARestartDecidedInTheSameTick(): void
    {
        $at = self::OPENED + 300;
        $older = self::service(1);
        $climbed = self::service(2, step: 2, lastResultAt: $at + 60, unit: 'php8.3-fpm.service');
        $actions = [self::action(1, ActionKind::Restart, $at, [2], 'php8.3-fpm.service')];

        $decision = self::instance($actions)->decide([$older, $climbed], true, true, $at + 60);

        $this->assertSame(['I would restart nginx.service now.'], self::sentences($decision));
    }

    public function testDryRunActionsCountOnlyAgainstDryRunActions(): void
    {
        $at = self::OPENED + 60_000;
        $actions = [self::action(1, ActionKind::Reset, $at - 100, [9])];

        $this->assertSame([], self::instance($actions)->decide([self::silent()], false, true, $at)->newActions);
        $this->assertCount(1, self::instance($actions)->decide([self::silent()], false, false, $at)->newActions);
    }

    public function testLadderPathsFollowTheDecisions(): void
    {
        $this->assertSame(LadderPath::Full, Ladder::path('service', self::NGINX, 'inactive'));
        $this->assertSame(LadderPath::AlertOnly, Ladder::path('service', self::NGINX, 'not_found'));
        $this->assertSame(LadderPath::AlertOnly, Ladder::path('local_http', null, 'refused'));
        $this->assertSame(LadderPath::Full, Ladder::path('local_http', self::NGINX, 'refused'));
        $this->assertSame(LadderPath::AlertOnly, Ladder::path('database', self::NGINX, 'login_refused'));
        $this->assertSame(LadderPath::Full, Ladder::path('database', self::NGINX, 'unreachable'));
        $this->assertSame(LadderPath::ResetOnly, Ladder::path(HeartbeatAgeRule::CHECK_NAME, null, null));

        foreach (['remote_http', 'local_certificate', 'remote_certificate', 'disk_size'] as $checkName) {
            $this->assertSame(LadderPath::AlertOnly, Ladder::path($checkName, null, null), $checkName);
        }

        $this->assertSame(self::NGINX, Ladder::unit('service', self::NGINX, null));
        $this->assertSame(self::NGINX, Ladder::unit('local_http', 'http://127.0.0.1/', self::NGINX));
        $this->assertNull(Ladder::unit('remote_http', 'https://example.com/', self::NGINX));
    }
}
