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
        $this->assertEquals(new CheckState(7, 'local_certificate', 'example.com', CheckOutcome::Pass, 100), CheckState::after(null, self::checkResult(CheckOutcome::Pass)));
        $this->assertEquals(new CheckState(7, 'local_certificate', 'example.com', CheckOutcome::Fail, 100), CheckState::after(null, self::checkResult(CheckOutcome::Fail)));
    }

    public function testAFlipIsAChangeAndARepeatIsNot(): void
    {
        $passing = new CheckState(7, 'local_certificate', 'example.com', CheckOutcome::Pass, 50);

        $this->assertEquals(new CheckState(7, 'local_certificate', 'example.com', CheckOutcome::Fail, 100), CheckState::after($passing, self::checkResult(CheckOutcome::Fail)));
        $this->assertNull(CheckState::after($passing, self::checkResult(CheckOutcome::Pass)));
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
