<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring;

/**
 * What the dashboard shows about the daily job (design section 6.3).
 */
final class DailyJobSummary
{
    /**
     * @param ?DailyJobRun $lastRun the latest run, whatever its state
     * @param ?DailyJobRun $lastSucceededRun the run the results come from
     * @param ?DailyJobRun $lastScheduledRun the timer's latest run
     * @param bool $overdue the timer's latest run started more than 25 hours ago
     * @param array<int, CheckResult> $diskSizeResults by instance ID, from $lastSucceededRun
     */
    public function __construct(
        public readonly ?DailyJobRun $lastRun,
        public readonly ?DailyJobRun $lastSucceededRun,
        public readonly ?DailyJobRun $lastScheduledRun,
        public readonly bool $overdue,
        public readonly array $diskSizeResults,
    ) {
    }
}
