<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Fleet;

use Maguari\Server\Fleet\InstanceStatus;
use PHPUnit\Framework\TestCase;

final class InstanceStatusTest extends TestCase
{
    public function testLabels(): void
    {
        $this->assertSame('Running', InstanceStatus::Running->label());
        $this->assertSame('Terminated', InstanceStatus::Terminated->label());
        $this->assertSame('Unknown', InstanceStatus::Unknown->label());
    }

    public function testStatusesAreCaseSensitive(): void
    {
        $this->assertSame(InstanceStatus::Running, InstanceStatus::fromComputeEngine('RUNNING'));
        $this->assertSame(InstanceStatus::Unknown, InstanceStatus::fromComputeEngine('running'));
        $this->assertSame(InstanceStatus::Unknown, InstanceStatus::fromComputeEngine(''));
    }
}
