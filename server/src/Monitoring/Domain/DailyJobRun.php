<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * One run of the daily job.
 */
final class DailyJobRun
{
    public function __construct(
        public readonly int $id,
        public readonly DailyJobTrigger $trigger,
        public readonly int $startedAt,
        public readonly ?int $finishedAt,
        public readonly DailyJobState $state,
    ) {
    }
}
