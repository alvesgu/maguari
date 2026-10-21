<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Monitoring\Domain;

use Maguari\Server\Monitoring\Domain\CheckOutcome;
use Maguari\Server\Monitoring\Domain\ClientCheck;
use Maguari\Server\Monitoring\Domain\ClientCheckResult;
use Maguari\Server\Monitoring\Domain\ClientCheckResults;
use Maguari\Server\Monitoring\Domain\ClientCheckSettings;
use Maguari\Server\Monitoring\Domain\LocalHttpCheckRule;
use Maguari\Server\Monitoring\Domain\LocalHttpTarget;
use Maguari\Server\Monitoring\Exception\InvalidCheckResults;
use Maguari\Server\Monitoring\Exception\InvalidClientCheck;
use PHPUnit\Framework\TestCase;

/**
 * Local HTTP checks on the server side (design section 6.1.3): what an
 * administrator may ask, the settings, the results and their sentences.
 */
final class LocalHttpCheckTest extends TestCase
{
    public function testNormalizesUrlsAndHostNames(): void
    {
        $this->assertSame(['http://127.0.0.1:8000/health', ''], LocalHttpTarget::normalize(' http://127.0.0.1:8000/health ', ''));
        $this->assertSame(['http://localhost/', 'geonode.example.com'], LocalHttpTarget::normalize('HTTP://LocalHost:80', ' GeoNode.Example.com '));
        $this->assertSame(['https://[::1]/a?b=1', 'geonode.example.com'], LocalHttpTarget::normalize('https://[::1]:443/a?b=1', 'geonode.example.com'));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function refused(): array
    {
        $itself = 'The URL must point at the instance itself: its host must be 127.0.0.1, [::1] or localhost. To reach a site by its name, put the name in Host name.';

        return [
            'empty' => ['', '', 'Enter a URL on the instance, for example http://127.0.0.1:8000/health.'],
            'a space' => ['http://127.0.0.1/a b', '', 'Enter the URL without spaces. Percent-encode other characters.'],
            'no scheme' => ['127.0.0.1:8000/health', '', 'Enter a full URL that starts with http:// or https://.'],
            'a fragment' => ['http://127.0.0.1/#top', '', 'Leave out the # part: it is never sent to the server.'],
            'a password' => ['http://admin:secret@127.0.0.1/', '', 'Leave out the user name and password: the check sends none.'],
            'a public site' => ['https://geonode.example.com/', '', $itself],
            'a private address' => ['http://10.128.0.5/', '', $itself],
            'the metadata server' => ['http://169.254.169.254/computeMetadata/v1/', '', $itself],
            'another loopback address' => ['http://127.0.0.2/', '', $itself],
            'a name that resolves to loopback' => ['http://127.0.0.1.nip.io/', '', $itself],
            'port zero' => ['http://127.0.0.1:0/', '', $itself],
            'an IP as host name' => ['http://127.0.0.1/', '10.0.0.1', 'Enter the host name as visitors use it, for example geonode.example.com: a full name, not an IP address or localhost.'],
            'localhost as host name' => ['http://127.0.0.1/', 'localhost', 'Enter the host name as visitors use it, for example geonode.example.com: a full name, not an IP address or localhost.'],
            'https without a host name' => ['https://127.0.0.1/', '', 'An https:// URL needs a host name, so the certificate can be checked against it.'],
        ];
    }

    /**
     * @dataProvider refused
     */
    public function testRefusesWithASentence(string $url, string $hostName, string $sentence): void
    {
        $this->expectException(InvalidClientCheck::class);
        $this->expectExceptionMessage($sentence);

        LocalHttpTarget::normalize($url, $hostName);
    }

    public function testTheSettingAndSubjectNameTheHostName(): void
    {
        $check = new ClientCheck(1, 7, 'local_http', 'https://127.0.0.1/health', 'geonode.example.com', 0);

        $this->assertSame(['type' => 'local_http', 'url' => 'https://127.0.0.1/health', 'host_name' => 'geonode.example.com'], $check->setting());
        $this->assertSame('https://127.0.0.1/health (geonode.example.com)', $check->subject());
        $this->assertSame('local_http', $check->checkName());
        $this->assertCount(1, ClientCheckSettings::of([$check])->checks);
    }

    public function testParsesResults(): void
    {
        $results = ClientCheckResults::parse([
            ['type' => 'local_http', 'subject' => 'http://127.0.0.1/', 'outcome' => 'pass', 'status' => 302, 'ms' => 4],
            ['type' => 'local_http', 'subject' => 'http://127.0.0.1:8000/', 'outcome' => 'fail', 'status' => 502, 'ms' => 1],
            ['type' => 'local_http', 'subject' => 'https://127.0.0.1/ (a.example.com)', 'outcome' => 'fail', 'reason' => 'refused'],
            ['type' => 'local_http', 'subject' => 'http://localhost/', 'outcome' => 'not_checked', 'reason' => 'time_limit'],
        ]);

        $this->assertEquals([
            new ClientCheckResult('local_http', 'http://127.0.0.1/', CheckOutcome::Pass, null, null, 302, 4),
            new ClientCheckResult('local_http', 'http://127.0.0.1:8000/', CheckOutcome::Fail, null, null, 502, 1),
            new ClientCheckResult('local_http', 'https://127.0.0.1/ (a.example.com)', CheckOutcome::Fail, 'refused'),
            new ClientCheckResult('local_http', 'http://localhost/', CheckOutcome::NotChecked, 'time_limit'),
        ], $results->results);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidResults(): array
    {
        $base = ['type' => 'local_http', 'subject' => 'http://127.0.0.1/'];

        return [
            'a pass that is a 500' => [$base + ['outcome' => 'pass', 'status' => 500, 'ms' => 1]],
            'a failure that is a 200' => [$base + ['outcome' => 'fail', 'status' => 200, 'ms' => 1]],
            'a status without a time' => [$base + ['outcome' => 'fail', 'status' => 500]],
            'a status out of range' => [$base + ['outcome' => 'fail', 'status' => 999, 'ms' => 1]],
            'a negative time' => [$base + ['outcome' => 'pass', 'status' => 200, 'ms' => -1]],
            'an unknown reason' => [$base + ['outcome' => 'fail', 'reason' => 'gremlins']],
            'a status and a reason' => [$base + ['outcome' => 'fail', 'status' => 500, 'ms' => 1, 'reason' => 'refused']],
            'a subject with a line break' => [['type' => 'local_http', 'subject' => "http://127.0.0.1/\n", 'outcome' => 'fail', 'reason' => 'refused']],
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
        $check = new ClientCheck(1, 7, 'local_http', 'https://127.0.0.1:8443/', 'geonode.example.com', 0);
        $plain = new ClientCheck(2, 7, 'local_http', 'http://127.0.0.1/', '', 0);
        $sentence = static fn (ClientCheck $check, ClientCheckResult $result): string => LocalHttpCheckRule::result($check, $result, 100)->detail;

        $this->assertSame('Answered 200 in 1,204 ms.', $sentence($check, new ClientCheckResult('local_http', '', CheckOutcome::Pass, null, null, 200, 1204)));
        $this->assertSame('Answered 502 in 3 ms.', $sentence($check, new ClientCheckResult('local_http', '', CheckOutcome::Fail, null, null, 502, 3)));
        $this->assertSame('Nothing answered on port 8443 of the instance: the connection was refused.', $sentence($check, new ClientCheckResult('local_http', '', CheckOutcome::Fail, 'refused')));
        $this->assertSame('Nothing answered on port 80 of the instance: the connection was refused.', $sentence($plain, new ClientCheckResult('local_http', '', CheckOutcome::Fail, 'refused')));
        $this->assertSame('No answer within 5 seconds.', $sentence($check, new ClientCheckResult('local_http', '', CheckOutcome::Fail, 'timeout')));
        $this->assertSame('The TLS connection failed: the certificate is not trusted or does not match geonode.example.com, or the handshake failed.', $sentence($check, new ClientCheckResult('local_http', '', CheckOutcome::Fail, 'tls_failed')));
        $this->assertSame('The answer was not an HTTP response.', $sentence($check, new ClientCheckResult('local_http', '', CheckOutcome::Fail, 'bad_response')));
        $this->assertSame('Could not connect to port 8443 of the instance.', $sentence($check, new ClientCheckResult('local_http', '', CheckOutcome::Fail, 'connect_failed')));

        $result = LocalHttpCheckRule::result($check, new ClientCheckResult('local_http', '', CheckOutcome::Pass, null, null, 200, 1), 100);
        $this->assertSame([7, 'local_http', 'https://127.0.0.1:8443/ (geonode.example.com)'], [$result->instanceId, $result->checkName, $result->subject]);
    }
}
