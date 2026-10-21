<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Monitoring\Domain;

use Maguari\Server\Monitoring\Domain\CheckOutcome;
use Maguari\Server\Monitoring\Domain\CheckResult;
use Maguari\Server\Monitoring\Domain\CheckState;
use PHPUnit\Framework\TestCase;

final class CheckStateTest extends TestCase
{
    private static function checkResult(CheckOutcome $outcome, int $at = 100): CheckResult
    {
        return new CheckResult(7, 'local_certificate', $outcome, 'A sentence.', $at, 'example.com');
    }

    public function testAFirstPassOrFailIsAChange(): void
    {
        $pass = CheckState::after(null, self::checkResult(CheckOutcome::Pass));
        $fail = CheckState::after(null, self::checkResult(CheckOutcome::Fail));

        $this->assertEquals(new CheckState(7, 'local_certificate', 'example.com', CheckOutcome::Pass, 100, 'A sentence.', 100), $pass);
        $this->assertEquals(new CheckState(7, 'local_certificate', 'example.com', CheckOutcome::Fail, 100, 'A sentence.', 100), $fail);
        $this->assertTrue($pass?->changedFrom(null));
    }

    public function testAFlipIsAChangeAndARepeatOnlyTakesTheNewSentence(): void
    {
        $passing = new CheckState(7, 'local_certificate', 'example.com', CheckOutcome::Pass, 50, 'An older sentence.');

        $failing = CheckState::after($passing, self::checkResult(CheckOutcome::Fail));
        $repeat = CheckState::after($passing, self::checkResult(CheckOutcome::Pass));

        $this->assertEquals(new CheckState(7, 'local_certificate', 'example.com', CheckOutcome::Fail, 100, 'A sentence.', 100), $failing);
        $this->assertTrue($failing?->changedFrom($passing));
        $this->assertEquals(new CheckState(7, 'local_certificate', 'example.com', CheckOutcome::Pass, 50, 'A sentence.', 100), $repeat);
        $this->assertFalse($repeat?->changedFrom($passing));
    }

    public function testNotCheckedChangesNothing(): void
    {
        $this->assertNull(CheckState::after(null, self::checkResult(CheckOutcome::NotChecked)));
        $this->assertNull(CheckState::after(new CheckState(7, 'local_certificate', 'example.com', CheckOutcome::Fail, 50), self::checkResult(CheckOutcome::NotChecked)));
    }

    public function testAStateIsNeverNotChecked(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CheckState(7, 'disk_size', '', CheckOutcome::NotChecked, 50);
    }
}
