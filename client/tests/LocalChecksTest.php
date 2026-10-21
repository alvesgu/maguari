<?php

declare(strict_types=1);

namespace Maguari\Client\Tests;

use Maguari\Client\CheckSettings;
use Maguari\Client\CheckSettingsFile;
use Maguari\Client\LocalChecks;
use Maguari\Client\ProcessSystemctl;
use Maguari\Client\Tests\Support\FakeClock;
use Maguari\Client\Tests\Support\FakeLocalHttp;
use Maguari\Client\Tests\Support\FakeSystemctl;
use Maguari\Client\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Check settings from the server, their file, and the service checks the
 * client runs from them (design section 6.1.3).
 */
final class LocalChecksTest extends TestCase
{
    private TemporaryDirectory $directory;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    /**
     * @param list<string> $units
     */
    private static function settings(array $units): CheckSettings
    {
        return CheckSettings::fromArray([
            'version' => '0123456789abcdef',
            'checks' => array_map(static fn (string $unit): array => ['type' => 'service', 'unit' => $unit], $units),
        ]) ?? throw new \LogicException('Invalid test settings.');
    }

    public function testSettingsKeepOnlyChecksThisClientCanRun(): void
    {
        $settings = CheckSettings::fromArray(['version' => '0123456789abcdef', 'checks' => [
            ['type' => 'service', 'unit' => 'nginx.service'],
            ['type' => 'service', 'unit' => 'nginx; reboot'],
            ['type' => 'service', 'unit' => '--root=/.service'],
            ['type' => 'database', 'name' => 'geonode'],
            ['type' => 'database', 'name' => 'Geo Node'],
            ['type' => 'from_the_future'],
            'not an object',
            ['type' => 'local_http', 'url' => 'http://127.0.0.1:8000/health', 'host_name' => ''],
            ['type' => 'local_http', 'url' => 'https://127.0.0.1/', 'host_name' => 'geonode.example.com'],
            ['type' => 'local_http', 'url' => 'http://10.0.0.5/', 'host_name' => ''],
            ['type' => 'local_http', 'url' => 'https://127.0.0.1/', 'host_name' => ''],
            ['type' => 'local_http', 'url' => 'http://127.0.0.1/'],
        ]]);

        $this->assertSame([
            ['type' => 'service', 'unit' => 'nginx.service'],
            ['type' => 'database', 'name' => 'geonode'],
            ['type' => 'local_http', 'url' => 'http://127.0.0.1:8000/health', 'host_name' => ''],
            ['type' => 'local_http', 'url' => 'https://127.0.0.1/', 'host_name' => 'geonode.example.com'],
        ], $settings?->checks);
        $this->assertSame(8, $settings?->skipped);
        $this->assertNull(CheckSettings::fromArray(['version' => 'nope', 'checks' => []]));
        $this->assertNull(CheckSettings::fromArray(['version' => '0123456789abcdef', 'checks' => ['a' => 1]]));
        $this->assertNull(CheckSettings::fromArray('settings'));
        $this->assertSame([], CheckSettings::fromArray(['version' => '0123456789abcdef', 'checks' => []])?->checks, 'No checks is a setting too.');
    }

    public function testTheSettingsFileIsPrivateAndSurvivesARestart(): void
    {
        $file = new CheckSettingsFile($this->directory->path . '/state');

        $this->assertNull($file->load(), 'None before the first.');
        $file->save(self::settings(['nginx.service']));

        $this->assertSame(0600, fileperms($file->path()) & 0777);
        $this->assertSame(0700, fileperms($this->directory->path . '/state') & 0777);
        $this->assertEquals(self::settings(['nginx.service']), (new CheckSettingsFile($this->directory->path . '/state'))->load());

        chmod($file->path(), 0644);
        $this->assertNull($file->load(), 'A file others can read is not trusted.');
        file_put_contents($file->path(), 'not json');
        chmod($file->path(), 0600);
        $this->assertNull($file->load());
    }

    public function testEachServiceStateBecomesAResult(): void
    {
        $systemctl = new FakeSystemctl();
        $systemctl->unit('nginx.service', 'active');
        $systemctl->unit('php8.3-fpm.service', 'reloading');
        $systemctl->unit('geonode.service', 'failed');
        $systemctl->unit('celery.service', 'activating');
        $systemctl->unit('broken.service', null);
        $systemctl->unit('odd.service', 'Weird State');

        $results = (new LocalChecks($systemctl, new FakeClock()))->run(self::settings(['nginx.service', 'php8.3-fpm.service', 'geonode.service', 'celery.service', 'missing.service', 'broken.service', 'odd.service']));

        $this->assertSame([
            ['type' => 'service', 'subject' => 'nginx.service', 'outcome' => 'pass', 'state' => 'active'],
            ['type' => 'service', 'subject' => 'php8.3-fpm.service', 'outcome' => 'pass', 'state' => 'reloading'],
            ['type' => 'service', 'subject' => 'geonode.service', 'outcome' => 'fail', 'reason' => 'inactive', 'state' => 'failed'],
            ['type' => 'service', 'subject' => 'celery.service', 'outcome' => 'fail', 'reason' => 'inactive', 'state' => 'activating'],
            ['type' => 'service', 'subject' => 'missing.service', 'outcome' => 'fail', 'reason' => 'not_found'],
            ['type' => 'service', 'subject' => 'broken.service', 'outcome' => 'not_checked', 'reason' => 'systemctl_failed'],
            ['type' => 'service', 'subject' => 'odd.service', 'outcome' => 'not_checked', 'reason' => 'systemctl_failed'],
        ], $results);
        $this->assertSame([], (new LocalChecks($systemctl, new FakeClock()))->run(null), 'No settings, no checks.');
    }

    public function testNoCheckStartsAfterTwentySeconds(): void
    {
        $clock = new FakeClock();
        $systemctl = new FakeSystemctl($clock, 5);

        $results = (new LocalChecks($systemctl, $clock))->run(self::settings(['a.service', 'b.service', 'c.service', 'd.service', 'e.service']));

        $this->assertSame(['a.service', 'b.service', 'c.service', 'd.service'], $systemctl->shown);
        $this->assertSame(['type' => 'service', 'subject' => 'e.service', 'outcome' => 'not_checked', 'reason' => 'time_limit'], $results[4]);
    }

    /**
     * A script in place of systemctl, which records its arguments.
     */
    private function fakeBinary(string $body): string
    {
        $path = $this->directory->path . '/systemctl-' . bin2hex(random_bytes(4));
        file_put_contents($path, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > {$path}.args\n{$body}\n");
        chmod($path, 0700);

        return $path;
    }

    public function testRunsSystemctlWithAnArgumentListAndReadsItsProperties(): void
    {
        $binary = $this->fakeBinary("printf 'LoadState=loaded\\nActiveState=active\\nSubState=running\\n'");

        $this->assertSame(['LoadState' => 'loaded', 'ActiveState' => 'active', 'SubState' => 'running'], (new ProcessSystemctl($binary))->show('nginx.service'));
        $this->assertSame("show\n--property=LoadState,ActiveState,SubState\n--\nnginx.service\n", file_get_contents($binary . '.args'));
    }

    public function testASystemctlThatFailsHangsOrIsMissingGivesNothing(): void
    {
        $this->assertNull((new ProcessSystemctl($this->fakeBinary('exit 1')))->show('nginx.service'));
        $this->assertNull((new ProcessSystemctl($this->fakeBinary("echo 'Nothing useful'")))->show('nginx.service'));
        $this->assertNull((new ProcessSystemctl($this->directory->path . '/no-such-systemctl'))->show('nginx.service'));

        $started = microtime(true);
        $this->assertNull((new ProcessSystemctl($this->fakeBinary('exec sleep 10'), 0.5))->show('nginx.service'));
        $this->assertLessThan(3.0, microtime(true) - $started);
    }

    public function testEachLocalHttpAnswerBecomesAResult(): void
    {
        $http = new FakeLocalHttp();
        $http->answer('http://127.0.0.1:8000/health', '', 503);
        $http->answer('https://127.0.0.1/', 'geonode.example.com', 'tls_failed');
        $settings = CheckSettings::fromArray(['version' => '0123456789abcdef', 'checks' => [
            ['type' => 'local_http', 'url' => 'http://127.0.0.1/', 'host_name' => ''],
            ['type' => 'local_http', 'url' => 'http://127.0.0.1:8000/health', 'host_name' => ''],
            ['type' => 'local_http', 'url' => 'https://127.0.0.1/', 'host_name' => 'geonode.example.com'],
        ]]);

        $results = (new LocalChecks(new FakeSystemctl(), new FakeClock(), $http))->run($settings);

        $this->assertSame([
            ['type' => 'local_http', 'subject' => 'http://127.0.0.1/', 'outcome' => 'pass', 'status' => 200, 'ms' => 12],
            ['type' => 'local_http', 'subject' => 'http://127.0.0.1:8000/health', 'outcome' => 'fail', 'status' => 503, 'ms' => 12],
            ['type' => 'local_http', 'subject' => 'https://127.0.0.1/ (geonode.example.com)', 'outcome' => 'fail', 'reason' => 'tls_failed'],
        ], $results);
        $this->assertSame([['http://127.0.0.1/', ''], ['http://127.0.0.1:8000/health', ''], ['https://127.0.0.1/', 'geonode.example.com']], $http->requests);
    }
}
