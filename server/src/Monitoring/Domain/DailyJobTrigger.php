<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * What started a run of the daily job, stored in
 * monitoring_daily_job_runs.triggered_by.
 */
enum DailyJobTrigger: string
{
    /** The systemd timer, through maguari-server run-daily-job. */
    case Scheduled = 'scheduled';

    /** An administrator pressing "Run now". */
    case Manual = 'manual';
}
