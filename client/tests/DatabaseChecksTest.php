<?php

declare(strict_types=1);

namespace Maguari\Client\Tests;

use Maguari\Client\CertificateExpiry;
use Maguari\Client\CheckSettings;
use Maguari\Client\Cli;
use Maguari\Client\ClientFailure;
use Maguari\Client\CredentialsFile;
use Maguari\Client\DatabaseConnection;
use Maguari\Client\DatabasesFile;
use Maguari\Client\DiskUsage;
use Maguari\Client\LocalChecks;
use Maguari\Client\PdoDatabaseConnector;
use Maguari\Client\Tests\Support\FakeClock;
use Maguari\Client\Tests\Support\FakeDatabaseConnector;
use Maguari\Client\Tests\Support\FakeFilesystemStats;
use Maguari\Client\Tests\Support\FakeLocalHttp;
use Maguari\Client\Tests\Support\FakeSystemctl;
use Maguari\Client\Tests\Support\FakeTransport;
use Maguari\Client\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Database checks on the client (design section 6.1.3): the databases file,
 * its commands and the check, with a fake connector. The real drivers are in
 * DatabaseServersTest.
 */
final class DatabaseChecksTest extends TestCase
{
    private TemporaryDirectory $directory;
    private FakeDatabaseConnector $connector;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
        $this->connector = new FakeDatabaseConnector();
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    private static function connection(string $database = 'geonode', string $type = 'postgresql'): DatabaseConnection
    {
        return new DatabaseConnection($type, '127.0.0.1', 5432, $database, 'maguari_check', 's3cret;"\' pass');
    }

    /**
     * @param string[] $args
     * @return array{int, string, string} exit code, stdout and stderr
     */
    private function cli(array $args, string $stdin = '', ?PdoDatabaseConnector $connector = null): array
    {
        [$input, $stdout, $stderr] = [fopen('php://memory', 'w+'), fopen('php://memory', 'w+'), fopen('php://memory', 'w+')];
        fwrite($input, $stdin);
        rewind($input);
        $cli = new Cli(
            new FakeTransport(),
            new FakeClock(),
            new DiskUsage(new FakeFilesystemStats(), __DIR__ . '/fixtures/mounts-gce'),
            new CertificateExpiry(__DIR__ . '/fixtures/missing'),
            new CredentialsFile($this->directory->path),
            new FakeSystemctl(),
            1000,
            $stdout,
            $stderr,
            new FakeLocalHttp(),
            $connector ?? $this->connector,
            $input,
        );
        $status = $cli->run(['maguari-client', ...$args]);
        rewind($stdout);
        rewind($stderr);

        return [$status, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }

    public function testConnectionsRefuseWhatCouldChangeTheDsn(): void
    {
        $this->assertNull(DatabaseConnection::problem('postgresql', '/var/run/postgresql', 5432, 'geonode', 'maguari_check', ''));
        $this->assertNull(DatabaseConnection::problem('mysql', 'db.internal', 3306, 'app', 'check@x', 'p;w=d'));
        $this->assertStringStartsWith('The type is', (string) DatabaseConnection::problem('sqlite', 'x', 1, 'a', 'b', ''));
        $this->assertStringStartsWith('The host is', (string) DatabaseConnection::problem('postgresql', 'a;dbname=x', 5432, 'a', 'b', ''));
        $this->assertStringStartsWith('The port is', (string) DatabaseConnection::problem('postgresql', 'x', 0, 'a', 'b', ''));
        $this->assertStringStartsWith('The database is', (string) DatabaseConnection::problem('postgresql', 'x', 5432, "geo' options='-c x", 'b', ''));
        $this->assertStringStartsWith('The user is', (string) DatabaseConnection::problem('postgresql', 'x', 5432, 'a', 'b c', ''));
        $this->assertStringStartsWith('The password has', (string) DatabaseConnection::problem('postgresql', 'x', 5432, 'a', 'b', "a\nb"));

        $this->assertSame("pgsql:host=127.0.0.1;port=5432;dbname=geonode;connect_timeout=5;options='-c statement_timeout=5000'", self::connection()->dsn());
        $this->assertSame('mysql:host=127.0.0.1;port=5432;dbname=app', self::connection('app', 'mysql')->dsn());
        $this->assertSame('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=app', (new DatabaseConnection('mysql', '/run/mysqld/mysqld.sock', 3306, 'app', 'u', ''))->dsn());
        $this->assertStringNotContainsString('s3cret', self::connection()->dsn() . self::connection()->describe());
    }

    public function testTheFileIsPrivateAndRefusedWhenOthersCanReadIt(): void
    {
        $file = new DatabasesFile($this->directory->path . '/state');
        $this->assertSame([], $file->load(), 'No file, no databases.');

        $file->save(['geonode' => self::connection(), 'app' => self::connection('app', 'mysql')]);

        $this->assertSame(0600, fileperms($file->path()) & 0777);
        $this->assertEquals(['app' => self::connection('app', 'mysql'), 'geonode' => self::connection()], $file->load());

        chmod($file->path(), 0640);

        try {
            $file->load();
            $this->fail('A file others can read was used.');
        } catch (ClientFailure $failure) {
            $this->assertStringContainsString('must be readable only by its owner', $failure->getMessage());
        }

        chmod($file->path(), 0600);
        file_put_contents($file->path(), '{"Bad Name": {}}');
        $this->expectExceptionMessage('has an entry that is not valid: Bad Name');
        $file->load();
    }

    /**
     * @return array<string, array{string, int|string, string}>
     */
    public static function driverMessages(): array
    {
        return [
            'PostgreSQL password' => ['SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5432 failed: FATAL:  password authentication failed for user "maguari_check"', '08006', 'login_refused'],
            'PostgreSQL pg_hba' => ['SQLSTATE[08006] [7] FATAL:  no pg_hba.conf entry for host "10.0.0.1", user "x", database "y"', '08006', 'login_refused'],
            'PostgreSQL role' => ['SQLSTATE[08006] [7] FATAL:  role "maguari_check" does not exist', '08006', 'login_refused'],
            'PostgreSQL database' => ['SQLSTATE[08006] [7] FATAL:  database "geonode" does not exist', '08006', 'no_database'],
            'PostgreSQL refused' => ['SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5432 failed: Connection refused', '08006', 'unreachable'],
            'PostgreSQL timeout' => ['SQLSTATE[08006] [7] timeout expired', '08006', 'timeout'],
            'PostgreSQL starting' => ['SQLSTATE[08006] [7] FATAL:  the database system is starting up', '08006', 'unreachable'],
            'MySQL access' => ['SQLSTATE[HY000] [1045] Access denied for user \'maguari_check\'@\'localhost\' (using password: YES)', 1045, 'login_refused'],
            'MySQL database' => ['SQLSTATE[HY000] [1049] Unknown database \'app\'', 1049, 'no_database'],
            'MariaDB database without rights' => ['SQLSTATE[HY000] [1044] Access denied for user \'check\'@\'%\' to database \'maguari_missing\'', 1044, 'no_database'],
            'MySQL refused' => ['SQLSTATE[HY000] [2002] Connection refused', 2002, 'unreachable'],
            'MySQL timeout' => ['SQLSTATE[HY000] [2002] Connection timed out', 2002, 'timeout'],
        ];
    }

    /**
     * @dataProvider driverMessages
     */
    public function testTellsConnectionFailuresApart(string $message, int|string $code, string $reason): void
    {
        $this->assertSame($reason, PdoDatabaseConnector::connectFailure($message, $code));
    }

    public function testAMissingDriverIsReportedWithoutConnecting(): void
    {
        $connector = new PdoDatabaseConnector(static fn (string $driver): bool => $driver === 'pdo_mysql');

        $this->assertSame('driver_missing', $connector->check(self::connection()));
        $this->assertTrue($connector->hasDriver('mysql'));
    }

    public function testEachDatabaseSetupBecomesAResult(): void
    {
        $file = new DatabasesFile($this->directory->path);
        $file->save(['geonode' => self::connection(), 'down' => self::connection('down'), 'old' => self::connection('old')]);
        $this->connector->answer('down', 'unreachable');
        $this->connector->answer('old', 'driver_missing');
        $settings = CheckSettings::fromArray(['version' => '0123456789abcdef', 'checks' => [
            ['type' => 'database', 'name' => 'geonode'],
            ['type' => 'database', 'name' => 'down'],
            ['type' => 'database', 'name' => 'old'],
            ['type' => 'database', 'name' => 'missing'],
        ]]);
        $checks = new LocalChecks(new FakeSystemctl(), new FakeClock(), new FakeLocalHttp(), $file, $this->connector);

        $this->assertSame([
            ['type' => 'database', 'subject' => 'geonode', 'outcome' => 'pass', 'ms' => 3],
            ['type' => 'database', 'subject' => 'down', 'outcome' => 'fail', 'reason' => 'unreachable'],
            ['type' => 'database', 'subject' => 'old', 'outcome' => 'not_checked', 'reason' => 'driver_missing', 'db_type' => 'postgresql'],
            ['type' => 'database', 'subject' => 'missing', 'outcome' => 'not_checked', 'reason' => 'not_set_up'],
        ], $checks->run($settings));

        chmod($file->path(), 0644);
        $this->assertSame([['type' => 'database', 'subject' => 'geonode', 'outcome' => 'not_checked', 'reason' => 'file_unusable']], $checks->run(CheckSettings::fromArray(['version' => '0123456789abcdef', 'checks' => [['type' => 'database', 'name' => 'geonode']]])));
    }

    public function testSetListCheckAndRemoveADatabase(): void
    {
        [$status, $stdout, $stderr] = $this->cli(['set-database', 'geonode', '--type=postgresql', '--host=127.0.0.1', '--database=geonode', '--user=maguari_check'], "s3cret pass\n");

        $this->assertSame(0, $status, $stderr);
        $this->assertStringStartsWith('Saved the database geonode (postgresql, 127.0.0.1:5432, database geonode, user maguari_check) to ', $stdout);
        $this->assertStringContainsString('Try it with: maguari-client check-database geonode', $stdout);
        $this->assertSame('s3cret pass', (new DatabasesFile($this->directory->path))->load()['geonode']->password);
        $this->assertSame(0600, fileperms($this->directory->path . '/databases.json') & 0777);

        $this->cli(['set-database', 'app', '--type=mysql', '--host=/run/mysqld/mysqld.sock', '--database=app', '--user=check'], '');
        [, $list] = $this->cli(['list-databases']);
        $this->assertSame("app: mysql, socket in /run/mysqld/mysqld.sock, database app, user check\ngeonode: postgresql, 127.0.0.1:5432, database geonode, user maguari_check\n", $list);
        $this->assertStringNotContainsString('s3cret', $list);

        $this->connector->answer('geonode', 'login_refused');
        [$status, $stdout] = $this->cli(['check-database', 'geonode']);
        $this->assertSame(1, $status);
        $this->assertStringContainsString('geonode (postgresql, 127.0.0.1:5432, database geonode, user maguari_check): Fail: the server refused the login.', $stdout);
        [$status, $stdout] = $this->cli(['check-database', 'app']);
        $this->assertSame([0, "app (mysql, socket in /run/mysqld/mysqld.sock, database app, user check): Pass: SELECT 1 answered in 3 ms.\n"], [$status, $stdout]);

        [$status, $stdout] = $this->cli(['remove-database', 'app']);
        $this->assertSame(0, $status);
        $this->assertSame(['geonode'], array_keys((new DatabasesFile($this->directory->path))->load()));
        [$status, , $stderr] = $this->cli(['remove-database', 'app']);
        $this->assertSame([1, "No database named app is set up. See them with: maguari-client list-databases\n"], [$status, $stderr]);
    }

    public function testSetDatabaseRefusesBadValuesBeforeAskingForThePassword(): void
    {
        [$status, , $stderr] = $this->cli(['set-database', 'geonode', '--type=oracle', '--host=127.0.0.1', '--database=geonode', '--user=u'], "never read\n");
        $this->assertSame(1, $status);
        $this->assertStringStartsWith('The type is postgresql (also for PostGIS) or mysql (also for MariaDB).', $stderr);

        [$status, , $stderr] = $this->cli(['set-database', 'Geo Node', '--type=postgresql']);
        $this->assertSame(1, $status);
        $this->assertStringStartsWith('Name the database first', $stderr);

        [$status, , $stderr] = $this->cli(['set-database', 'geonode', '--type=postgresql', '--host=127.0.0.1']);
        $this->assertSame(1, $status);
        $this->assertStringStartsWith('Usage:', $stderr);
        $this->assertFileDoesNotExist($this->directory->path . '/databases.json');
    }

    public function testSetDatabaseSaysWhenTheDriverIsMissing(): void
    {
        [$status, $stdout] = $this->cli(['set-database', 'geonode', '--type=postgresql', '--host=127.0.0.1', '--database=geonode', '--user=u'], "pw\n", new PdoDatabaseConnector(static fn (): bool => false));

        $this->assertSame(0, $status);
        $this->assertStringContainsString("The postgresql driver is not installed, so the check cannot run yet. Install it with: sudo apt install php-pgsql\n", $stdout);
    }

    public function testDatabaseCommandsRefuseRoot(): void
    {
        [$input, $stdout, $stderr] = [fopen('php://memory', 'w+'), fopen('php://memory', 'w+'), fopen('php://memory', 'w+')];
        $cli = new Cli(new FakeTransport(), new FakeClock(), new DiskUsage(new FakeFilesystemStats(), __DIR__ . '/fixtures/mounts-gce'), new CertificateExpiry(__DIR__ . '/fixtures/missing'), new CredentialsFile($this->directory->path), new FakeSystemctl(), 0, $stdout, $stderr, new FakeLocalHttp(), $this->connector, $input);

        $this->assertSame(1, $cli->run(['maguari-client', 'list-databases']));
        rewind($stderr);
        $this->assertStringContainsString('sudo -u maguari-client maguari-client list-databases', (string) stream_get_contents($stderr));
    }
}
