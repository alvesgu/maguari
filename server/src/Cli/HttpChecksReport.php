<?php

declare(strict_types=1);

namespace Maguari\Server\Cli;

use Maguari\Server\Monitoring\Domain\CheckResult;

/**
 * What `maguari-server run-http-checks` prints (design section 12.2.1): one
 * line per check it ran, nothing when none was due. Pure, so it is tested
 * without running the checks.
 */
final class HttpChecksReport
{
    /**
     * @param CheckResult[] $results as MonitoringApi::runDueHttpChecks() returns them
     * @param array<int, string> $names each instance's name, by instance ID
     * @return list<string> without line endings
     */
    public static function lines(array $results, array $names): array
    {
        return array_map(
            static fn (CheckResult $result): string => sprintf(
                '%s: Web app %s: %s. %s',
                $names[$result->instanceId] ?? "instance {$result->instanceId}",
                $result->subject,
                $result->outcome->label(),
                $result->detail,
            ),
            $results,
        );
    }
}
