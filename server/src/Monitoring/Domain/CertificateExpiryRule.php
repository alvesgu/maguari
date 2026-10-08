<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * Judges a certificate's expiry date (design section 6.3): one rule for the
 * certificates the instance reports and, later, the ones it serves. No
 * database or network access.
 */
final class CertificateExpiryRule
{
    public const LOCAL_CHECK_NAME = 'local_certificate';

    /**
     * Fewer whole days left than this fails. certbot renews 90-day
     * certificates with 30 days left and tries twice a day, so this means
     * about two weeks of failed renewals. It becomes a setting when 45-day
     * certificates arrive (design section 17 item 3).
     */
    public const MIN_DAYS_LEFT = 14;

    private const SECONDS_PER_DAY = 86_400;

    /**
     * One result per certificate the instance reported recently. A
     * certificate that is no longer reported drops out with its readings, so
     * a removed certificate does not fail forever. An instance that reports
     * none gets no result, not "Not checked": most instances have none.
     *
     * @param array<string, MetricRun> $runs the current certificate_expires_at
     *        run of each domain, by domain
     * @return list<CheckResult>
     */
    public function checkLocal(int $instanceId, array $runs, int $at): array
    {
        $results = [];

        foreach ($runs as $domain => $run) {
            if (!$run->isRecent($at)) {
                continue;
            }

            [$outcome, $detail] = $this->judge((int) $run->value, $at);

            if ($outcome === CheckOutcome::Fail) {
                $detail .= ' certbot renews well before expiry, so renewal is failing on this instance.';
            }

            $results[] = new CheckResult($instanceId, self::LOCAL_CHECK_NAME, $outcome, $detail, $at, (string) $domain);
        }

        return $results;
    }

    /**
     * Days are whole days, rounded down, and dates are UTC.
     *
     * @param int $expiresAt the certificate's last valid second
     * @return array{CheckOutcome, string}
     */
    public function judge(int $expiresAt, int $at): array
    {
        $date = gmdate('Y-m-d', $expiresAt);

        if ($expiresAt < $at) {
            return [CheckOutcome::Fail, sprintf('Expired on %s.', $date)];
        }

        $days = intdiv($expiresAt - $at, self::SECONDS_PER_DAY);

        if ($days < self::MIN_DAYS_LEFT) {
            return [CheckOutcome::Fail, sprintf('Expires on %s, %s.', $date, match ($days) {
                0 => 'in less than a day',
                1 => 'in 1 day',
                default => sprintf('in %d days', $days),
            })];
        }

        return [CheckOutcome::Pass, sprintf('Valid until %s (%d days).', $date, $days)];
    }
}
