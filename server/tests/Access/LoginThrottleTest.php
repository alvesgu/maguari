<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Access;

use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

final class LoginThrottleTest extends TestCase
{
    private TestEnvironment $environment;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
    }

    protected function tearDown(): void
    {
        $this->environment->cleanUp();
    }

    public function testTenFailuresWithinFifteenMinutesLimitTheIp(): void
    {
        $access = $this->environment->access;

        for ($i = 0; $i < 9; $i++) {
            $access->recordFailedAttempt('192.0.2.1');
            $this->environment->clock->advance(60);
        }

        $this->assertFalse($access->isThrottled('192.0.2.1'));
        $access->recordFailedAttempt('192.0.2.1');
        $this->assertTrue($access->isThrottled('192.0.2.1'));
        $this->assertFalse($access->isThrottled('192.0.2.2'));
    }

    public function testFailuresOlderThanTheWindowNoLongerCount(): void
    {
        $access = $this->environment->access;

        for ($i = 0; $i < 10; $i++) {
            $access->recordFailedAttempt('192.0.2.1');
        }

        $this->environment->clock->advance(899);
        $this->assertTrue($access->isThrottled('192.0.2.1'));
        $this->environment->clock->advance(1);
        $this->assertFalse($access->isThrottled('192.0.2.1'));
    }
}
