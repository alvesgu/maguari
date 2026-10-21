<?php

declare(strict_types=1);

namespace Maguari\Client\Tests;

use Maguari\Client\DatabaseConnection;
use Maguari\Client\PdoDatabaseConnector;
use PHPUnit\Framework\TestCase;

/**
 * The real PDO drivers against real servers. Skipped unless the server is
 * named, because neither test suite needs a database server:
 * MAGUARI_TEST_POSTGRESQL and MAGUARI_TEST_MYSQL, each as
 * "host:port:database:user:password" for a user that can log in, and with
 * the driver installed.
 */
final class DatabaseServersTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function servers(): array
    {
        return ['PostgreSQL' => ['postgresql', 'MAGUARI_TEST_POSTGRESQL'], 'MySQL or MariaDB' => ['mysql', 'MAGUARI_TEST_MYSQL']];
    }

    /**
     * @dataProvider servers
     */
    public function testChecksARealServer(string $type, string $variable): void
    {
        $server = getenv($variable);
        $connector = new PdoDatabaseConnector();

        if (!is_string($server) || $server === '' || !$connector->hasDriver($type)) {
            $this->markTestSkipped("Set {$variable} and install the driver to check a real server.");
        }

        [$host, $port, $database, $user, $password] = explode(':', $server, 5);
        $connection = static fn (string $database, string $password): DatabaseConnection => new DatabaseConnection($type, $host, (int) $port, $database, $user, $password);

        $this->assertIsInt($connector->check($connection($database, $password)));
        $this->assertSame('login_refused', $connector->check($connection($database, $password . '-wrong')));
        $this->assertSame('no_database', $connector->check($connection('maguari_missing', $password)));
        $this->assertSame('unreachable', $connector->check(new DatabaseConnection($type, '127.0.0.1', 1, $database, $user, $password)));
    }
}
