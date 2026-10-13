<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Remediation\Domain;

use Maguari\Server\Remediation\Domain\Incident;
use Maguari\Server\Remediation\Domain\IncidentKey;
use Maguari\Server\Remediation\Domain\Resolution;
use PHPUnit\Framework\TestCase;

final class IncidentTest extends TestCase
{
    private const AT = 1_790_000_000;

    private static function opened(): Incident
    {
        return Incident::open(new IncidentKey(7, 'heartbeat_age', ''), self::AT)->withId(1);
    }

    public function testOpensWithoutPassingOrResolution(): void
    {
        $incident = self::opened();

        $this->assertTrue($incident->isOpen());
        $this->assertSame(self::AT, $incident->openedAt);
        $this->assertNull($incident->passingSince);
        $this->assertNull($incident->resolution);
    }

    public function testResolvesAtExactlyFiveMinutesOfPassing(): void
    {
        $passing = self::opened()->passed(self::AT + 60);

        $this->assertNull($passing->resolveIfDue(self::AT + 60 + 299));
        $resolved = $passing->resolveIfDue(self::AT + 60 + 300);
        $this->assertNotNull($resolved);
        $this->assertFalse($resolved->isOpen());
        $this->assertSame(self::AT + 360, $resolved->resolvedAt);
        $this->assertSame(Resolution::Passed, $resolved->resolution);
    }

    public function testAFailureWithinFiveMinutesStartsTheWaitAgain(): void
    {
        $again = self::opened()->passed(self::AT + 60)->failedAgain();

        $this->assertNull($again->passingSince);
        $this->assertNull($again->resolveIfDue(self::AT + 3600));
        $this->assertSame(self::AT + 400, $again->passed(self::AT + 400)->passingSince);
    }

    public function testASecondPassKeepsTheEarlierTime(): void
    {
        $this->assertSame(self::AT + 60, self::opened()->passed(self::AT + 60)->passed(self::AT + 120)->passingSince);
    }

    public function testWithdrawResolvesAtOnce(): void
    {
        $withdrawn = self::opened()->withdraw(self::AT + 5);

        $this->assertSame(Resolution::Withdrawn, $withdrawn->resolution);
        $this->assertSame(self::AT + 5, $withdrawn->resolvedAt);
    }

    public function testReconcile(): void
    {
        $incident = self::opened();
        $at = self::AT + 3600;

        $this->assertSame(Resolution::Reconciled, $incident->reconcile(null, $at)->resolution, 'Monitoring has no outcome for it.');
        $this->assertSame(Resolution::Reconciled, $incident->reconcile($at - 300, $at)->resolution, 'Passing for 5 minutes.');
        $waiting = $incident->reconcile($at - 299, $at);
        $this->assertTrue($waiting->isOpen(), 'Passing for less: the normal rule resolves it.');
        $this->assertSame($at - 299, $waiting->passingSince);
    }

    public function testAResolvedIncidentNeverChanges(): void
    {
        $this->expectException(\LogicException::class);

        self::opened()->withdraw(self::AT)->passed(self::AT + 60);
    }
}
