<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Monitoring\Domain;

use Maguari\Server\Monitoring\Domain\CheckOutcome;
use Maguari\Server\Monitoring\Domain\ClientCheck;
use Maguari\Server\Monitoring\Domain\ClientCheckResult;
use Maguari\Server\Monitoring\Domain\ClientCheckResults;
use Maguari\Server\Monitoring\Domain\ClientCheckSettings;
use Maguari\Server\Monitoring\Domain\ServiceCheckRule;
use Maguari\Server\Monitoring\Domain\ServiceUnit;
use Maguari\Server\Monitoring\Exception\InvalidCheckResults;
use Maguari\Server\Monitoring\Exception\InvalidClientCheck;
use PHPUnit\Framework\TestCase;

/**
 * The rules of client checks (design section 6.1.3): unit names, the
 * settings and their version, the results a heartbeat may carry and their
 * sentences.
 */
final class ClientCheckTest extends TestCase
{
    public function testNormalizesServiceNames(): void
    {
        $this->assertSame('nginx.service', ServiceUnit::normalize(' nginx '));
        $this->assertSame('nginx.service', ServiceUnit::normalize('nginx.service'));
        $this->assertSame('getty@tty1.service', ServiceUnit::normalize('getty@tty1'));
        $this->assertSame('php8.1-fpm.service', ServiceUnit::normalize('php8.1-fpm'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedUnits(): array
    {
        $characters = 'A service name has only letters, digits and : _ . \\ - characters, and at most one @ before a template\'s instance.';

        return [
            'empty' => ['', 'Enter a service name, for example nginx or nginx.service.'],
            'a socket' => ['nginx.socket', 'Only services can be checked: enter a name ending in .service, or leave the suffix out.'],
            'a timer' => ['certbot.timer', 'Only services can be checked: enter a name ending in .service, or leave the suffix out.'],
            'an option' => ['--root=/', 'A service name cannot start with - or a dot.'],
            'a dot' => ['.hidden', 'A service name cannot start with - or a dot.'],
            'a template' => ['getty@', 'getty@.service is a template: enter one of its instances, for example getty@tty1.service.'],
            'a shell command' => ['nginx; reboot', $characters],
            'a path' => ['../../bin/sh', 'A service name cannot start with - or a dot.'],
            'a slash' => ['a/b', $characters],
            'two templates' => ['a@b@c', $characters],
            'too long' => [str_repeat('a', 250), 'A service name has at most 255 characters.'],
        ];
    }

    /**
     * @dataProvider refusedUnits
     */
    public function testRefusesUnitsWithASentence(string $input, string $sentence): void
    {
        $this->expectException(InvalidClientCheck::class);
        $this->expectExceptionMessage($sentence);

        ServiceUnit::normalize($input);
    }

    public function testTheSettingsVersionDependsOnlyOnTheChecks(): void
    {
        $nginx = new ClientCheck(1, 7, 'service', 'nginx.service', '', 100);
        $php = new ClientCheck(2, 7, 'service', 'php.service', '', 200);

        $settings = ClientCheckSettings::of([$php, $nginx]);

        $this->assertSame([['type' => 'service', 'unit' => 'nginx.service'], ['type' => 'service', 'unit' => 'php.service']], $settings->checks);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/D', $settings->version);
        $this->assertSame($settings->version, ClientCheckSettings::of([new ClientCheck(9, 8, 'service', 'nginx.service', '', 999), $php])->version, 'Order, IDs and times do not matter.');
        $this->assertNotSame($settings->version, ClientCheckSettings::of([$nginx])->version);
        $this->assertSame(substr(hash('sha256', '[]'), 0, 16), ClientCheckSettings::of([])->version, 'No checks has a version too.');
    }

    public function testParsesResultsAndLeavesOutUnknownTypes(): void
    {
        $results = ClientCheckResults::parse([
            ['type' => 'service', 'subject' => 'nginx.service', 'outcome' => 'pass', 'state' => 'active'],
            ['type' => 'service', 'subject' => 'php.service', 'outcome' => 'fail', 'reason' => 'inactive', 'state' => 'failed'],
            ['type' => 'service', 'subject' => 'gone.service', 'outcome' => 'fail', 'reason' => 'not_found'],
            ['type' => 'service', 'subject' => 'slow.service', 'outcome' => 'not_checked', 'reason' => 'time_limit'],
            ['type' => 'from_the_future', 'subject' => 'x', 'outcome' => 'pass'],
        ]);

        $this->assertEquals([
            new ClientCheckResult('service', 'nginx.service', CheckOutcome::Pass, null, 'active'),
            new ClientCheckResult('service', 'php.service', CheckOutcome::Fail, 'inactive', 'failed'),
            new ClientCheckResult('service', 'gone.service', CheckOutcome::Fail, 'not_found'),
            new ClientCheckResult('service', 'slow.service', CheckOutcome::NotChecked, 'time_limit'),
        ], $results->results);
    }

    /**
     * @return array<string, array{list<mixed>}>
     */
    public static function invalidResults(): array
    {
        $pass = ['type' => 'service', 'subject' => 'nginx.service', 'outcome' => 'pass', 'state' => 'active'];

        return [
            'not an object' => [['nginx']],
            'a list' => [[['service']]],
            'no type' => [[['subject' => 'nginx.service']]],
            'an invalid unit' => [[['type' => 'service', 'subject' => 'nginx; reboot', 'outcome' => 'pass', 'state' => 'active']]],
            'an unknown outcome' => [[['type' => 'service', 'subject' => 'nginx.service', 'outcome' => 'maybe']]],
            'a pass with a reason' => [[$pass + ['reason' => 'inactive']]],
            'a pass that is not active' => [[['type' => 'service', 'subject' => 'nginx.service', 'outcome' => 'pass', 'state' => 'failed']]],
            'a failure that is active' => [[['type' => 'service', 'subject' => 'nginx.service', 'outcome' => 'fail', 'reason' => 'inactive', 'state' => 'active']]],
            'an unknown reason' => [[['type' => 'service', 'subject' => 'nginx.service', 'outcome' => 'fail', 'reason' => 'gremlins']]],
            'a sentence as state' => [[['type' => 'service', 'subject' => 'nginx.service', 'outcome' => 'fail', 'reason' => 'inactive', 'state' => 'It is <b>down</b>']]],
            'twice' => [[$pass, $pass]],
            'too many' => [array_fill(0, 51, ['type' => 'from_the_future'])],
        ];
    }

    /**
     * @dataProvider invalidResults
     * @param list<mixed> $checks
     */
    public function testRefusesInvalidResults(array $checks): void
    {
        $this->expectException(InvalidCheckResults::class);

        ClientCheckResults::parse($checks);
    }

    public function testWordsEachServiceResult(): void
    {
        $sentence = static fn (ClientCheckResult $result): string => ServiceCheckRule::result(7, $result, 100)->detail;

        $this->assertSame('nginx.service is active.', $sentence(new ClientCheckResult('service', 'nginx.service', CheckOutcome::Pass, null, 'active')));
        $this->assertSame('nginx.service is failed, not active.', $sentence(new ClientCheckResult('service', 'nginx.service', CheckOutcome::Fail, 'inactive', 'failed')));
        $this->assertSame('There is no unit nginx.service on the instance.', $sentence(new ClientCheckResult('service', 'nginx.service', CheckOutcome::Fail, 'not_found')));
        $this->assertSame('The client could not run systemctl to read the state of nginx.service.', $sentence(new ClientCheckResult('service', 'nginx.service', CheckOutcome::NotChecked, 'systemctl_failed')));
        $this->assertSame('The client ran out of time for its checks before this one.', $sentence(new ClientCheckResult('service', 'nginx.service', CheckOutcome::NotChecked, 'time_limit')));
        $result = ServiceCheckRule::result(7, new ClientCheckResult('service', 'nginx.service', CheckOutcome::Pass, null, 'active'), 100);
        $this->assertSame([7, 'service', 'nginx.service', 100], [$result->instanceId, $result->checkName, $result->subject, $result->checkedAt]);
    }
}
