<?php

declare(strict_types=1);

namespace Maguari\Client;

use Maguari\Shared\Protocol;

/**
 * Sends a heartbeat every interval on a fixed schedule: the next one is due
 * at a multiple of the interval after the start, however long a heartbeat
 * took, so the schedule never drifts. Failures are logged and the loop goes
 * on.
 */
final class Runner
{
    /**
     * @param \Closure(string): void $log
     */
    public function __construct(
        private readonly HeartbeatSender $sender,
        private readonly Clock $clock,
        private readonly \Closure $log,
        private readonly int $intervalSeconds = Protocol::HEARTBEAT_INTERVAL_SECONDS,
    ) {
    }

    /**
     * @param int|null $heartbeats stop after this many (for tests); null runs forever
     */
    public function run(Credentials $credentials, ?int $heartbeats = null): void
    {
        ($this->log)(sprintf('Sending a heartbeat to %s every %d seconds.', $credentials->serverUrl, $this->intervalSeconds));
        $due = $this->clock->now();
        $failing = false;

        for ($sent = 0; $heartbeats === null || $sent < $heartbeats; $sent++) {
            try {
                $this->sender->send($credentials);

                if ($failing) {
                    ($this->log)('Heartbeats are getting through again.');
                    $failing = false;
                }
            } catch (ClientFailure $failure) {
                ($this->log)('Heartbeat failed: ' . $failure->getMessage());
                $failing = true;
            }

            // Skip slots that already passed (for example after a long timeout)
            // instead of sending a burst to catch up.
            do {
                $due += $this->intervalSeconds;
            } while ($due <= $this->clock->now());

            if ($heartbeats === null || $sent + 1 < $heartbeats) {
                $this->clock->sleep($due - $this->clock->now());
            }
        }
    }
}
