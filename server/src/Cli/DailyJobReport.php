<?php

declare(strict_types=1);

namespace Maguari\Server\Cli;

use Maguari\Server\Monitoring\Domain\CertificateExpiryRule;
use Maguari\Server\Monitoring\Domain\CheckOutcome;
use Maguari\Server\Monitoring\Domain\CheckResult;
use Maguari\Server\Monitoring\Domain\DiskSizeRule;

/**
 * What `maguari-server run-daily-job` prints (design section 12.2.1): one
 * line per instance and check, then a summary. Pure, so it is tested without
 * running the job.
 */
final class DailyJobReport
{
    /**
     * @param CheckResult[] $results as MonitoringApi::runDailyJob() returns them
     * @param array<int, string> $names each instance's name, by instance ID
     * @return list<string> without line endings
     */
    public static function lines(array $results, array $names): array
    {
        $counts = array_fill_keys(array_map(static fn (CheckOutcome $outcome): string => $outcome->value, CheckOutcome::cases()), 0);
        $lines = [];

        foreach ($results as $result) {
            $counts[$result->outcome->value]++;
            $name = $names[$result->instanceId] ?? "instance {$result->instanceId}";
            $lines[] = "{$name}: " . self::check($result) . ": {$result->outcome->label()}. {$result->detail}";
        }

        if ($results === []) {
            $lines[] = 'No instances to check.';
        }

        $lines[] = sprintf(
            'Daily job finished: %d passed, %d failed, %d not checked.',
            $counts[CheckOutcome::Pass->value],
            $counts[CheckOutcome::Fail->value],
            $counts[CheckOutcome::NotChecked->value],
        );

        return $lines;
    }

    private static function check(CheckResult $result): string
    {
        return match ($result->checkName) {
            DiskSizeRule::CHECK_NAME => 'Disk size',
            CertificateExpiryRule::LOCAL_CHECK_NAME => "Certificate {$result->subject} on the instance",
            CertificateExpiryRule::REMOTE_CHECK_NAME => "Certificate served for {$result->subject}",
            default => trim("{$result->checkName} {$result->subject}"),
        };
    }
}
