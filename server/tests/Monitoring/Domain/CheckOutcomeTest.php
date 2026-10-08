<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Monitoring\Domain;

use Maguari\Server\Monitoring\Domain\CheckOutcome;
use PHPUnit\Framework\TestCase;

final class CheckOutcomeTest extends TestCase
{
    public function testTheWorstOutcome(): void
    {
        $this->assertNull(CheckOutcome::worst([]));
        $this->assertSame(CheckOutcome::Pass, CheckOutcome::worst([CheckOutcome::Pass, CheckOutcome::Pass]));
        $this->assertSame(CheckOutcome::NotChecked, CheckOutcome::worst([CheckOutcome::Pass, CheckOutcome::NotChecked]));
        $this->assertSame(CheckOutcome::Fail, CheckOutcome::worst([CheckOutcome::NotChecked, CheckOutcome::Fail, CheckOutcome::Pass]));
    }
}
