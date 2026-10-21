<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Monitoring\Domain;

use Maguari\Server\Monitoring\Domain\CheckOutcome;
use Maguari\Server\Monitoring\Domain\HttpAnswer;
use Maguari\Server\Monitoring\Domain\HttpCheck;
use Maguari\Server\Monitoring\Domain\HttpCheckEgress;
use Maguari\Server\Monitoring\Domain\HttpCheckRule;
use Maguari\Server\Monitoring\Domain\HttpCheckUrl;
use Maguari\Server\Monitoring\Domain\HttpFailure;
use Maguari\Server\Monitoring\Exception\InvalidHttpCheck;
use PHPUnit\Framework\TestCase;

/**
 * The rules of remote HTTP checks (design section 6.2.1): URLs, intervals,
 * the schedule, the egress estimate and the judgment.
 */
final class HttpCheckTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function acceptedUrls(): array
    {
        return [
            'an empty path' => ['https://www.example.com', 'https://www.example.com/'],
            'lowercased scheme and host' => ['  HTTPS://WWW.Example.COM/Health?Full=1 ', 'https://www.example.com/Health?Full=1'],
            'the default port dropped' => ['https://example.com:443/a', 'https://example.com/a'],
            'http on its default port' => ['http://example.com:80/', 'http://example.com/'],
            'another port kept' => ['https://example.com:8443/', 'https://example.com:8443/'],
            'percent-encoding kept' => ['https://xn--bcher-kva.example/caf%C3%A9', 'https://xn--bcher-kva.example/caf%C3%A9'],
        ];
    }

    /**
     * @dataProvider acceptedUrls
     */
    public function testNormalizesUrls(string $input, string $stored): void
    {
        $this->assertSame($stored, HttpCheckUrl::normalize($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedUrls(): array
    {
        $full = 'Enter a full URL that starts with https:// or http://.';
        $host = 'The URL needs a full hostname, such as www.example.com: not an IP address or a single name like localhost.';

        return [
            'empty' => ['  ', 'Enter a URL, for example https://www.example.com/.'],
            'a space' => ['https://example.com/a b', 'Enter the URL without spaces. Percent-encode other characters, and enter internationalized names in their xn-- form.'],
            'non-ASCII' => ['https://bücher.example/', 'Enter the URL without spaces. Percent-encode other characters, and enter internationalized names in their xn-- form.'],
            'too long' => ['https://example.com/' . str_repeat('a', 2030), 'A URL has at most 2,048 characters.'],
            'no scheme' => ['www.example.com', $full],
            'another scheme' => ['ftp://example.com/', $full],
            'no slashes' => ['https:example.com', $full],
            'a port out of range' => ['https://example.com:70000/', $full],
            'user and password' => ['https://jane:secret@example.com/', 'Leave out the user name and password: the check sends none.'],
            'a fragment' => ['https://example.com/#top', 'Leave out the # part: browsers never send it to the server.'],
            'an IPv4 address' => ['http://10.0.0.1/', $host],
            'an IPv6 address' => ['http://[::1]/', $host],
            'localhost' => ['http://localhost:8080/', $host],
            'a wildcard' => ['https://*.example.com/', $host],
            'the metadata server' => ['http://metadata.google.internal/computeMetadata/v1/', 'Names ending in .internal belong to Google Cloud\'s internal network, not to public web apps.'],
            'port zero' => ['https://example.com:0/', 'A port is a number from 1 to 65535.'],
        ];
    }

    /**
     * @dataProvider refusedUrls
     */
    public function testRefusesUrlsWithASentence(string $input, string $sentence): void
    {
        $this->expectException(InvalidHttpCheck::class);
        $this->expectExceptionMessage($sentence);

        HttpCheckUrl::normalize($input);
    }

    public function testIntervalsAreWholeMinutesFromOneToSixty(): void
    {
        $this->assertSame(600, HttpCheck::intervalSeconds(''), 'The default.');
        $this->assertSame(60, HttpCheck::intervalSeconds(' 1 '));
        $this->assertSame(3600, HttpCheck::intervalSeconds('60'));

        foreach (['0', '61', '1.5', '-5', 'ten', '010'] as $refused) {
            try {
                HttpCheck::intervalSeconds($refused);
                $this->fail("{$refused} was accepted.");
            } catch (InvalidHttpCheck $invalid) {
                $this->assertSame('Enter an interval of 1 to 60 minutes.', $invalid->getMessage());
            }
        }
    }

    public function testTheScheduleSkipsSlotsThatPassed(): void
    {
        $check = new HttpCheck(1, 7, 'https://example.com/', 600, 0, 1000);

        $this->assertSame(1600, $check->nextDueAfter(1000));
        $this->assertSame(1600, $check->nextDueAfter(1030), 'A run that started late keeps the schedule.');
        $this->assertSame(2800, $check->nextDueAfter(2200), 'Missed slots are skipped, not caught up.');
    }

    public function testEgressEstimatesMatchTheDesign(): void
    {
        $this->assertSame(91, HttpCheckEgress::megabytes(HttpCheckEgress::monthlyBytes(60)));
        $this->assertSame(18, HttpCheckEgress::megabytes(HttpCheckEgress::monthlyBytes(300)));
        $this->assertSame(9, HttpCheckEgress::megabytes(HttpCheckEgress::monthlyBytes(600)));
        $this->assertSame(2, HttpCheckEgress::megabytes(HttpCheckEgress::monthlyBytes(3600)));
        $this->assertSame(4464 * 2048, HttpCheckEgress::monthlyBytes(600));
        $this->assertLessThanOrEqual(HttpCheckEgress::MONTHLY_BUDGET_BYTES, HttpCheckEgress::totalMonthlyBytes(array_fill(0, 21, 600)), '21 at the default stay within the budget.');
        $this->assertGreaterThan(HttpCheckEgress::MONTHLY_BUDGET_BYTES, HttpCheckEgress::totalMonthlyBytes(array_fill(0, 22, 600)));
        $this->assertLessThanOrEqual(HttpCheckEgress::MONTHLY_LIMIT_BYTES, HttpCheckEgress::totalMonthlyBytes(array_fill(0, 54, 600)), '54 at the default stay within the limit.');
        $this->assertGreaterThan(HttpCheckEgress::MONTHLY_LIMIT_BYTES, HttpCheckEgress::totalMonthlyBytes(array_fill(0, 55, 600)));
    }

    public function testExcessSaysTheShortestIntervalThatFits(): void
    {
        $minute = HttpCheckEgress::monthlyBytes(60);

        $this->assertNull(HttpCheckEgress::excess(0, 60));
        $this->assertNull(HttpCheckEgress::excess($minute, 60), 'Two at 1 minute stay within the budget.');

        $warning = HttpCheckEgress::excess(21 * HttpCheckEgress::monthlyBytes(600), 600);
        $this->assertFalse($warning?->refused);
        $this->assertSame('Saved, but all web app checks together now send about 202 MB a month, above the 200 MB suggested for them. An interval of 12 minutes or more would stay within it.', $warning?->sentence);

        $refusal = HttpCheckEgress::excess(5 * $minute, 60);
        $this->assertTrue($refusal?->refused);
        $this->assertSame('Not saved: all web app checks together would send about 549 MB a month, above the limit of 500 MB that keeps the free tier\'s 1 GB safe. An interval of 3 minutes or more would fit.', $refusal?->sentence);

        $this->assertStringEndsWith('Even an interval of 60 minutes would not fit: remove another check first.', (string) HttpCheckEgress::excess(499_500_000, 60)?->sentence);
        $this->assertStringEndsWith('Even an interval of 60 minutes would not stay within it, so consider removing a check.', (string) HttpCheckEgress::excess(199_500_000, 3600)?->sentence);
        $this->assertSame(1, HttpCheckEgress::shortestIntervalMinutes(100_000_000, HttpCheckEgress::MONTHLY_BUDGET_BYTES));
    }

    /**
     * @return array<string, array{HttpAnswer, CheckOutcome, string}>
     */
    public static function answers(): array
    {
        return [
            '200' => [HttpAnswer::status(200, 340), CheckOutcome::Pass, 'Answered 200 in 340 ms.'],
            'a redirect' => [HttpAnswer::status(302, 1250), CheckOutcome::Pass, 'Answered 302 in 1,250 ms.'],
            '404' => [HttpAnswer::status(404, 20), CheckOutcome::Fail, 'Answered 404 in 20 ms.'],
            '503' => [HttpAnswer::status(503, 20), CheckOutcome::Fail, 'Answered 503 in 20 ms.'],
            '101' => [HttpAnswer::status(101, 20), CheckOutcome::Fail, 'Answered 101 in 20 ms.'],
            'DNS' => [HttpAnswer::failure(HttpFailure::NameNotResolved), CheckOutcome::Fail, 'The name www.example.com did not resolve in DNS.'],
            'refused' => [HttpAnswer::failure(HttpFailure::Refused), CheckOutcome::Fail, 'www.example.com refused the connection on port 8443.'],
            'timeout' => [HttpAnswer::failure(HttpFailure::TimedOut), CheckOutcome::Fail, 'No answer within 10 seconds.'],
            'TLS' => [HttpAnswer::failure(HttpFailure::TlsFailed), CheckOutcome::Fail, 'The TLS connection failed: the certificate is not trusted or does not match the hostname, or the handshake failed.'],
            'connect' => [HttpAnswer::failure(HttpFailure::ConnectFailed), CheckOutcome::Fail, 'Could not connect to www.example.com on port 8443.'],
            'not HTTP' => [HttpAnswer::failure(HttpFailure::BadResponse), CheckOutcome::Fail, 'The answer was not an HTTP response.'],
        ];
    }

    /**
     * @dataProvider answers
     */
    public function testJudgesEachAnswerWithASentence(HttpAnswer $answer, CheckOutcome $outcome, string $sentence): void
    {
        $check = new HttpCheck(1, 7, 'https://www.example.com:8443/health', 600, 0, 0);

        $result = HttpCheckRule::result($check, $answer, 1234);

        $this->assertSame($outcome, $result->outcome);
        $this->assertSame($sentence, $result->detail);
        $this->assertSame([7, 'remote_http', 'https://www.example.com:8443/health', 1234], [$result->instanceId, $result->checkName, $result->subject, $result->checkedAt]);
    }

    public function testAFailureAfterTheRetrySaysSoAndTheDefaultPortIsNamed(): void
    {
        $check = new HttpCheck(1, 7, 'https://www.example.com/', 600, 0, 0);

        $this->assertSame('www.example.com refused the connection on port 443. Tried twice, 5 seconds apart.', HttpCheckRule::result($check, HttpAnswer::failure(HttpFailure::Refused), 0, retried: true)->detail);
        $this->assertSame('Answered 200 in 5 ms.', HttpCheckRule::result($check, HttpAnswer::status(200, 5), 0, retried: true)->detail);
    }
}
