<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Monitoring;

use Maguari\Server\Monitoring\MetricRun;
use Maguari\Server\Monitoring\Reading;
use Maguari\Server\Monitoring\RunDecision;
use Maguari\Server\Monitoring\RunRule;
use PHPUnit\Framework\TestCase;

final class RunRuleTest extends TestCase
{
    private const END_AT = 1_790_000_000;

    private function decide(int $value, int $gap, int $deadband = 0, int $interval = 60): RunDecision
    {
        $current = new MetricRun(1, 1000, self::END_AT - 600, self::END_AT);

        return (new RunRule($interval))->decide($current, new Reading('disk_used_bytes:/', $value, $deadband), self::END_AT + $gap);
    }

    public function testTheFirstReadingStartsARun(): void
    {
        $this->assertSame(RunDecision::Insert, (new RunRule(60))->decide(null, new Reading('disk_used_bytes:/', 1000), self::END_AT));
    }

    public function testTheSameValueExtendsUpTo1Point5TimesTheInterval(): void
    {
        $this->assertSame(RunDecision::Extend, $this->decide(1000, 0));
        $this->assertSame(RunDecision::Extend, $this->decide(1000, 60));
        $this->assertSame(RunDecision::Extend, $this->decide(1000, 90));
        $this->assertSame(RunDecision::Insert, $this->decide(1000, 91));
    }

    public function testTheGapLimitFollowsTheInterval(): void
    {
        $this->assertSame(RunDecision::Extend, $this->decide(1000, 450, interval: 300));
        $this->assertSame(RunDecision::Insert, $this->decide(1000, 451, interval: 300));
        // 1.5 times 15 is 22.5 seconds.
        $this->assertSame(RunDecision::Extend, $this->decide(1000, 22, interval: 15));
        $this->assertSame(RunDecision::Insert, $this->decide(1000, 23, interval: 15));
    }

    public function testADifferentValueStartsARun(): void
    {
        $this->assertSame(RunDecision::Insert, $this->decide(1001, 60));
        $this->assertSame(RunDecision::Insert, $this->decide(999, 60));
    }

    public function testANewValueInTheSameSecondStartsARun(): void
    {
        $this->assertSame(RunDecision::Insert, $this->decide(1001, 0));
    }

    public function testAReadingBeforeTheRunsEndIsIgnored(): void
    {
        $this->assertSame(RunDecision::Ignore, $this->decide(1000, -1));
        $this->assertSame(RunDecision::Ignore, $this->decide(2000, -1));
    }

    public function testADeadbandExtendsWithinItsWidthOfTheRunsValue(): void
    {
        $this->assertSame(RunDecision::Extend, $this->decide(1010, 60, deadband: 10));
        $this->assertSame(RunDecision::Extend, $this->decide(990, 60, deadband: 10));
        $this->assertSame(RunDecision::Insert, $this->decide(1011, 60, deadband: 10));
        $this->assertSame(RunDecision::Insert, $this->decide(989, 60, deadband: 10));
    }

    public function testADeadbandDoesNotHideAGap(): void
    {
        $this->assertSame(RunDecision::Insert, $this->decide(1000, 91, deadband: 10));
    }

    public function testRejectsAnIntervalBelowOneSecond(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RunRule(0);
    }
}
