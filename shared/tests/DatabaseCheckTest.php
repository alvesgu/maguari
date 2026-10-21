<?php

declare(strict_types=1);

namespace Maguari\Shared\Tests;

use Maguari\Shared\DatabaseCheck;
use PHPUnit\Framework\TestCase;

final class DatabaseCheckTest extends TestCase
{
    public function testNames(): void
    {
        foreach (['geonode', 'db1', 'my-app_db', '0', str_repeat('a', 63)] as $name) {
            $this->assertTrue(DatabaseCheck::isValidName($name), $name);
        }

        foreach (['', 'GeoNode', '-db', '_db', 'my db', 'a;b', 'db.prod', str_repeat('a', 64), "db\n"] as $name) {
            $this->assertFalse(DatabaseCheck::isValidName($name), $name);
        }
    }
}
