<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * Judges how old an instance's last heartbeat is (design section 6.2): late
 * when older than 1.5 times the instance's interval, the same limit as a gap
 * in runs (RunRule). No database or clock access.
 */
final class HeartbeatAgeRule
{
    public const CHECK_NAME = 'heartbeat_age';

    /**
     * A tick this long after the previous one means ticks had stopped (the
     * server was off or the timer was not running), so they resume now.
     */
    public const TICKS_RESUME_AFTER_SECONDS = 180;

    /**
     * The oldest a heartbeat can be and still be on time.
     */
    public static function maxAgeSeconds(int $intervalSeconds): int
    {
        return (new RunRule($intervalSeconds))->maxGapSeconds();
    }

    /**
     * When ticks count as resumed for the tick at $at: now when there was no
     * previous tick or it ran more than TICKS_RESUME_AFTER_SECONDS ago,
     * otherwise unchanged.
     */
    public static function ticksResumedAt(?int $previousTickAt, ?int $previousResumedAt, int $at): int
    {
        if ($previousTickAt === null || $previousResumedAt === null || $at - $previousTickAt > self::TICKS_RESUME_AFTER_SECONDS) {
            return $at;
        }

        return $previousResumedAt;
    }

    /**
     * The check's result for one instance. The age is measured from the
     * heartbeat, or from when ticks resumed if that is later: while the
     * server was not ticking, nobody watched, so a heartbeat only becomes
     * late once the server has been ticking for longer than the limit.
     */
    public static function check(int $instanceId, int $lastHeartbeatAt, int $intervalSeconds, int $ticksResumedAt, int $at): CheckResult
    {
        $maxAge = self::maxAgeSeconds($intervalSeconds);
        $age = $at - max($lastHeartbeatAt, $ticksResumedAt);
        $lastAt = gmdate('Y-m-d H:i:s', $lastHeartbeatAt) . ' UTC';

        if ($age <= $maxAge) {
            return new CheckResult($instanceId, self::CHECK_NAME, CheckOutcome::Pass, "The last heartbeat arrived at {$lastAt}.", $at);
        }

        return new CheckResult(
            $instanceId,
            self::CHECK_NAME,
            CheckOutcome::Fail,
            "No heartbeat since {$lastAt}. Heartbeats are expected every {$intervalSeconds} seconds and are late after {$maxAge}.",
            $at,
        );
    }

    /**
     * The state shown on pages, computed when the page is read. It does not
     * wait for ticks resuming: it says how old the heartbeat is.
     */
    public static function status(?int $lastHeartbeatAt, int $intervalSeconds, int $at): HeartbeatStatus
    {
        if ($lastHeartbeatAt === null) {
            return new HeartbeatStatus(HeartbeatState::NoHeartbeatYet, null, null);
        }

        $age = max(0, $at - $lastHeartbeatAt);

        return new HeartbeatStatus(
            $age <= self::maxAgeSeconds($intervalSeconds) ? HeartbeatState::OnTime : HeartbeatState::Late,
            $lastHeartbeatAt,
            $age,
        );
    }
}
