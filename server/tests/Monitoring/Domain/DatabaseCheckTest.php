<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Monitoring\Domain;

use Maguari\Server\Monitoring\Domain\CheckOutcome;
use Maguari\Server\Monitoring\Domain\ClientCheck;
use Maguari\Server\Monitoring\Domain\ClientCheckResult;
use Maguari\Server\Monitoring\Domain\ClientCheckResults;
use Maguari\Server\Monitoring\Domain\DatabaseCheckRule;
use Maguari\Server\Monitoring\Exception\InvalidCheckResults;
use PHPUnit\Framework\TestCase;

/**
 * Database checks on the server side (design section 6.1.3): only a name,
 * the results and their sentences.
 */
final class DatabaseCheckTest extends TestCase
{
    public function testTheSettingIsOnlyTheName(): void
    {
        $check = new ClientCheck(1, 7, 'database', 'geonode', '', 0);

        $this->assertSame(['type' => 'database', 'name' => 'geonode'], $check->setting());
        $this->assertSame(['database', 'geonode'], [$check->checkName(), $check->subject()]);
    }

    public function testParsesResults(): void
    {
        $this->assertEquals([
            new ClientCheckResult('database', 'geonode', CheckOutcome::Pass, null, null, null, 12),
            new ClientCheckResult('database', 'app', CheckOutcome::Fail, 'login_refused'),
            new ClientCheckResult('database', 'old', CheckOutcome::NotChecked, 'driver_missing', 'mysql'),
            new ClientCheckResult('database', 'new', CheckOutcome::NotChecked, 'not_set_up'),
        ], ClientCheckResults::parse([
            ['type' => 'database', 'subject' => 'geonode', 'outcome' => 'pass', 'ms' => 12],
            ['type' => 'database', 'subject' => 'app', 'outcome' => 'fail', 'reason' => 'login_refused'],
            ['type' => 'database', 'subject' => 'old', 'outcome' => 'not_checked', 'reason' => 'driver_missing', 'db_type' => 'mysql'],
            ['type' => 'database', 'subject' => 'new', 'outcome' => 'not_checked', 'reason' => 'not_set_up'],
        ])->results);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidResults(): array
    {
        $base = ['type' => 'database', 'subject' => 'geonode'];

        return [
            'an invalid name' => [['type' => 'database', 'subject' => 'Geo Node', 'outcome' => 'pass', 'ms' => 1]],
            'a pass without a time' => [$base + ['outcome' => 'pass']],
            'a failure with a time' => [$base + ['outcome' => 'fail', 'reason' => 'timeout', 'ms' => 1]],
            'a setup problem as a failure' => [$base + ['outcome' => 'fail', 'reason' => 'not_set_up']],
            'a failure as not checked' => [$base + ['outcome' => 'not_checked', 'reason' => 'unreachable']],
            'a missing driver of no known type' => [$base + ['outcome' => 'not_checked', 'reason' => 'driver_missing', 'db_type' => 'oracle']],
            'a type without a missing driver' => [$base + ['outcome' => 'fail', 'reason' => 'timeout', 'db_type' => 'mysql']],
            'a message instead of a reason' => [$base + ['outcome' => 'fail', 'reason' => 'FATAL: password authentication failed']],
        ];
    }

    /**
     * @dataProvider invalidResults
     * @param array<string, mixed> $result
     */
    public function testRefusesInvalidResults(array $result): void
    {
        $this->expectException(InvalidCheckResults::class);

        ClientCheckResults::parse([$result]);
    }

    public function testWordsEachResult(): void
    {
        $sentence = static fn (?string $reason, ?string $type = null, ?int $ms = null): string => DatabaseCheckRule::result(7, new ClientCheckResult('database', 'geonode', $reason === null ? CheckOutcome::Pass : CheckOutcome::Fail, $reason, $type, null, $ms), 100)->detail;

        $this->assertSame('SELECT 1 answered in 1,500 ms.', $sentence(null, null, 1500));
        $this->assertSame('Could not connect to the database server of geonode, or it is not accepting connections.', $sentence('unreachable'));
        $this->assertSame('The database server of geonode did not answer within 5 seconds.', $sentence('timeout'));
        $this->assertSame('The database server refused the login of geonode. Try it on the instance with: maguari-client check-database geonode', $sentence('login_refused'));
        $this->assertSame('The database that geonode names does not exist on its server, or its user may not use it.', $sentence('no_database'));
        $this->assertSame('The login of geonode worked, but SELECT 1 failed.', $sentence('query_failed'));
        $this->assertSame('No database named geonode is set up on the instance. Run maguari-client set-database geonode there.', $sentence('not_set_up'));
        $this->assertStringStartsWith('The client\'s database file cannot be used', $sentence('file_unusable'));
        $this->assertSame('The PostgreSQL driver is not installed on the instance. Install it with: sudo apt install php-pgsql', $sentence('driver_missing', 'postgresql'));
        $this->assertSame('The MySQL and MariaDB driver is not installed on the instance. Install it with: sudo apt install php-mysql', $sentence('driver_missing', 'mysql'));
    }
}
