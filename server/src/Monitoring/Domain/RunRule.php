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
     * The longest gap after a run's end that still continues it: 1.5 times
     * the expected interval, rounded down (gaps are whole seconds, so this is
     * the same as gap * 2 <= interval * 3). Charts use it to tell an outage
     * from the step between two runs (design section 10.1).
     */
    public function maxGapSeconds(): int
    {
        return intdiv(3 * $this->expectedIntervalSeconds, 2);
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
        $withinGap = $at - $current->endAt <= $this->maxGapSeconds();

        return $withinDeadband && $withinGap ? RunDecision::Extend : RunDecision::Insert;
    }
}
