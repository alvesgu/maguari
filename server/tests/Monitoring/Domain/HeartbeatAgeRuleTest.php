<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Monitoring\Domain;

use Maguari\Server\Monitoring\Domain\CheckOutcome;
use Maguari\Server\Monitoring\Domain\HeartbeatAgeRule;
use Maguari\Server\Monitoring\Domain\HeartbeatState;
use PHPUnit\Framework\TestCase;

final class HeartbeatAgeRuleTest extends TestCase
{
    private const AT = 1_790_000_000;

    public function testTheLimitIsTheRunRulesGap(): void
    {
        $this->assertSame(90, HeartbeatAgeRule::maxAgeSeconds(60));
        $this->assertSame(22, HeartbeatAgeRule::maxAgeSeconds(15));
    }

    public function testPassesAtTheLimitAndFailsOneSecondPastIt(): void
    {
        $atLimit = HeartbeatAgeRule::check(7, self::AT - 90, 60, self::AT - 3600, self::AT);
        $past = HeartbeatAgeRule::check(7, self::AT - 91, 60, self::AT - 3600, self::AT);

        $this->assertSame(CheckOutcome::Pass, $atLimit->outcome);
        $this->assertSame('heartbeat_age', $atLimit->checkName);
        $this->assertSame('', $atLimit->subject);
        $this->assertSame(self::AT, $atLimit->checkedAt);
        $this->assertSame('The last heartbeat arrived at ' . gmdate('Y-m-d H:i:s', self::AT - 90) . ' UTC.', $atLimit->detail);
        $this->assertSame(CheckOutcome::Fail, $past->outcome);
        $this->assertSame(
            'No heartbeat since ' . gmdate('Y-m-d H:i:s', self::AT - 91) . ' UTC. Heartbeats are expected every 60 seconds and are late after 90.',
            $past->detail,
        );
    }

    public function testAgeIsMeasuredFromWhenTicksResumedWhenThatIsLater(): void
    {
        $this->assertSame(CheckOutcome::Pass, HeartbeatAgeRule::check(7, self::AT - 3600, 60, self::AT - 90, self::AT)->outcome);
        $this->assertSame(CheckOutcome::Fail, HeartbeatAgeRule::check(7, self::AT - 3600, 60, self::AT - 91, self::AT)->outcome);
    }

    public function testTicksResumeAfterMoreThanThreeMinutes(): void
    {
        $this->assertSame(self::AT, HeartbeatAgeRule::ticksResumedAt(null, null, self::AT), 'The first tick.');
        $this->assertSame(self::AT - 3600, HeartbeatAgeRule::ticksResumedAt(self::AT - 180, self::AT - 3600, self::AT));
        $this->assertSame(self::AT, HeartbeatAgeRule::ticksResumedAt(self::AT - 181, self::AT - 3600, self::AT));
        $this->assertSame(self::AT - 3600, HeartbeatAgeRule::ticksResumedAt(self::AT + 30, self::AT - 3600, self::AT), 'The clock stepped back.');
    }

    public function testStatusIsTheHeartbeatsOwnAge(): void
    {
        $none = HeartbeatAgeRule::status(null, 60, self::AT);
        $onTime = HeartbeatAgeRule::status(self::AT - 90, 60, self::AT);
        $late = HeartbeatAgeRule::status(self::AT - 91, 60, self::AT);

        $this->assertSame(HeartbeatState::NoHeartbeatYet, $none->state);
        $this->assertNull($none->lastHeartbeatAt);
        $this->assertNull($none->ageSeconds);
        $this->assertSame(HeartbeatState::OnTime, $onTime->state);
        $this->assertSame(self::AT - 90, $onTime->lastHeartbeatAt);
        $this->assertSame(90, $onTime->ageSeconds);
        $this->assertSame(HeartbeatState::Late, $late->state);
        $this->assertSame(0, HeartbeatAgeRule::status(self::AT + 5, 60, self::AT)->ageSeconds, 'A heartbeat from the future is not negative.');
    }
}
