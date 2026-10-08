<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Monitoring\Domain;

use Maguari\Server\Monitoring\Domain\CertificateExpiryRule;
use Maguari\Server\Monitoring\Domain\CheckOutcome;
use Maguari\Server\Monitoring\Domain\CheckResult;
use Maguari\Server\Monitoring\Domain\MetricRun;
use Maguari\Server\Monitoring\Domain\ServedCertificate;
use PHPUnit\Framework\TestCase;

final class CertificateExpiryRuleTest extends TestCase
{
    /** 2026-10-08 06:00:00 UTC. */
    private const AT = 1_791_439_200;

    private const DAY = 86_400;

    /**
     * @return array<string, array{int, CheckOutcome, string}>
     */
    public static function expiries(): array
    {
        return [
            '60 days' => [self::AT + 60 * self::DAY, CheckOutcome::Pass, 'Valid until 2026-12-07 (60 days).'],
            'exactly 14 days' => [self::AT + 14 * self::DAY, CheckOutcome::Pass, 'Valid until 2026-10-22 (14 days).'],
            'one second short of 14 days' => [self::AT + 14 * self::DAY - 1, CheckOutcome::Fail, 'Expires on 2026-10-22, in 13 days.'],
            '1 day' => [self::AT + self::DAY + 3600, CheckOutcome::Fail, 'Expires on 2026-10-09, in 1 day.'],
            'less than a day' => [self::AT + 3600, CheckOutcome::Fail, 'Expires on 2026-10-08, in less than a day.'],
            'its last valid second' => [self::AT, CheckOutcome::Fail, 'Expires on 2026-10-08, in less than a day.'],
            'expired' => [self::AT - 1, CheckOutcome::Fail, 'Expired on 2026-10-08.'],
            'expired days ago' => [self::AT - 8 * self::DAY, CheckOutcome::Fail, 'Expired on 2026-09-30.'],
        ];
    }

    /**
     * @dataProvider expiries
     */
    public function testJudgesTheDaysLeft(int $expiresAt, CheckOutcome $outcome, string $detail): void
    {
        $this->assertSame([$outcome, $detail], (new CertificateExpiryRule())->judge($expiresAt, self::AT));
    }

    public function testOneLocalResultPerRecentCertificate(): void
    {
        $results = (new CertificateExpiryRule())->checkLocal(7, [
            'example.com' => new MetricRun(1, self::AT + 60 * self::DAY, self::AT - 600, self::AT),
            'old.example.com' => new MetricRun(2, self::AT - self::DAY, self::AT - 9000, self::AT - MetricRun::RECENT_FOR_SECONDS),
            'removed.example.com' => new MetricRun(3, self::AT + 60 * self::DAY, self::AT - 9000, self::AT - MetricRun::RECENT_FOR_SECONDS - 1),
        ], self::AT);

        $this->assertEquals([
            new CheckResult(7, 'local_certificate', CheckOutcome::Pass, 'Valid until 2026-12-07 (60 days).', self::AT, 'example.com'),
            new CheckResult(
                7,
                'local_certificate',
                CheckOutcome::Fail,
                'Expired on 2026-10-07. certbot renews well before expiry, so renewal is failing on this instance.',
                self::AT,
                'old.example.com',
            ),
        ], $results);
    }

    /**
     * @return array<string, array{?ServedCertificate, ?MetricRun, CheckOutcome, string}>
     */
    public static function served(): array
    {
        $renewed = new MetricRun(1, self::AT + 80 * self::DAY, self::AT - 600, self::AT);
        $reload = ' The instance has a renewed certificate, valid until 2026-12-27: reload the web server.';

        return [
            'trusted and valid' => [new ServedCertificate(self::AT + 60 * self::DAY, true), null, CheckOutcome::Pass, 'Valid until 2026-12-07 (60 days).'],
            'trusted and expiring' => [new ServedCertificate(self::AT + 4 * self::DAY, true), null, CheckOutcome::Fail, 'Expires on 2026-10-12, in 4 days.'],
            'expiring while the instance has a renewed one' => [
                new ServedCertificate(self::AT + 4 * self::DAY, true), $renewed, CheckOutcome::Fail, 'Expires on 2026-10-12, in 4 days.' . $reload,
            ],
            'expired while the instance has a renewed one' => [
                new ServedCertificate(self::AT - 8 * self::DAY, false), $renewed, CheckOutcome::Fail, 'Expired on 2026-09-30.' . $reload,
            ],
            'the same date on the instance' => [
                new ServedCertificate(self::AT + 4 * self::DAY, true), new MetricRun(1, self::AT + 4 * self::DAY, self::AT - 600, self::AT), CheckOutcome::Fail, 'Expires on 2026-10-12, in 4 days.',
            ],
            'a renewed one no longer reported' => [
                new ServedCertificate(self::AT + 4 * self::DAY, true),
                new MetricRun(1, self::AT + 80 * self::DAY, self::AT - 9000, self::AT - MetricRun::RECENT_FOR_SECONDS - 1),
                CheckOutcome::Fail,
                'Expires on 2026-10-12, in 4 days.',
            ],
            'not trusted' => [new ServedCertificate(self::AT + 60 * self::DAY, false), $renewed, CheckOutcome::Fail, 'The served certificate is not trusted or does not match the hostname.'],
            'no connection' => [null, $renewed, CheckOutcome::NotChecked, 'Could not connect on port 443.'],
        ];
    }

    /**
     * @dataProvider served
     */
    public function testJudgesTheServedCertificate(?ServedCertificate $served, ?MetricRun $local, CheckOutcome $outcome, string $detail): void
    {
        $this->assertEquals(
            new CheckResult(7, 'remote_certificate', $outcome, $detail, self::AT, 'www.example.com'),
            (new CertificateExpiryRule())->checkRemote(7, 'www.example.com', $served, $local, self::AT),
        );
    }

    public function testNoCertificatesGiveNoResults(): void
    {
        $this->assertSame([], (new CertificateExpiryRule())->checkLocal(7, [], self::AT));
    }
}
