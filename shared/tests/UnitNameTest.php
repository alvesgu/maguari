<?php

declare(strict_types=1);

namespace Maguari\Shared\Tests;

use Maguari\Shared\UnitName;
use PHPUnit\Framework\TestCase;

final class UnitNameTest extends TestCase
{
    public function testAcceptsServiceUnits(): void
    {
        foreach (['nginx.service', 'php8.1-fpm.service', 'getty@tty1.service', 'systemd-fsck@dev-disk-by\x2duuid-1234.service', 'a:b_c.service', 'docker.service'] as $unit) {
            $this->assertTrue(UnitName::isValid($unit), $unit);
        }

        $this->assertTrue(UnitName::isValid(str_repeat('a', 247) . '.service'), '255 bytes.');
    }

    public function testRefusesEverythingElse(): void
    {
        foreach ([
            '', 'nginx', 'nginx.socket', 'nginx.service.d', '.service', '-nginx.service', '.nginx.service',
            'getty@.service', 'a@b@c.service', 'nginx .service', 'nginx;reboot.service', "nginx\n.service",
            '../nginx.service', 'nginx/.service', 'ngînx.service', '--help.service', str_repeat('a', 248) . '.service',
        ] as $unit) {
            $this->assertFalse(UnitName::isValid($unit), $unit);
        }
    }
}
