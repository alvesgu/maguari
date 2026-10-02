<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * Decides what one reading does to its metric's current run (design section
 * 9.1). No database access.
 */
final class RunRule
{
    /**
     * @param int $expectedIntervalSeconds how often readings are expected. A
     *        gap of more than 1.5 times this starts a new run even when the
     *        value is unchanged, so outages stay visible.
     */
    public function __construct(
        private readonly int $expectedIntervalSeconds,
    ) {
        if ($expectedIntervalSeconds < 1) {
            throw new \InvalidArgumentException('The expected interval must be at least 1 second.');
        }
    }

    /**
     * @param ?MetricRun $current the run with the latest start_at (then the highest ID)
     * @param int $at the server's receive time of the reading
     */
    public function decide(?MetricRun $current, Reading $reading, int $at): RunDecision
    {
        if ($current === null) {
            return RunDecision::Insert;
        }

        // The server's clock stepped back: runs never overlap or go backwards.
        if ($at < $current->endAt) {
            return RunDecision::Ignore;
        }

        // Compared with the value that started the run, not the latest
        // reading, so a slow drift cannot stay inside the deadband forever.
        $withinDeadband = abs($reading->value - $current->value) <= $reading->deadband;
        // gap <= 1.5 * interval, in integers.
        $withinGap = 2 * ($at - $current->endAt) <= 3 * $this->expectedIntervalSeconds;

        return $withinDeadband && $withinGap ? RunDecision::Extend : RunDecision::Insert;
    }
}
