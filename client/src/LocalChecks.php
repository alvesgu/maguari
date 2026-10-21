<?php

declare(strict_types=1);

namespace Maguari\Client;

use Maguari\Shared\Protocol;
use Maguari\Shared\ServiceCheck;

/**
 * Runs the checks in the settings before each heartbeat and gives their
 * results as codes and values, never sentences: the server words them
 * (design section 5.2). No check starts after TIME_LIMIT_SECONDS; the rest
 * are reported as not checked, so the heartbeat always leaves on time.
 */
final class LocalChecks
{
    public const TIME_LIMIT_SECONDS = 20;

    public function __construct(
        private readonly Systemctl $systemctl,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<array<string, string>>
     */
    public function run(?CheckSettings $settings): array
    {
        $start = $this->clock->now();
        $results = [];

        foreach ($settings?->checks ?? [] as $check) {
            if ($this->clock->now() - $start >= self::TIME_LIMIT_SECONDS) {
                $results[] = ['type' => $check['type'], 'subject' => $check['unit'], 'outcome' => 'not_checked', 'reason' => Protocol::CHECK_REASON_TIME_LIMIT];

                continue;
            }

            $results[] = $this->service($check['unit']);
        }

        return $results;
    }

    /**
     * @return array<string, string>
     */
    private function service(string $unit): array
    {
        $result = ['type' => ServiceCheck::TYPE, 'subject' => $unit];
        $properties = $this->systemctl->show($unit);
        $state = $properties['ActiveState'] ?? '';

        return $result + match (true) {
            $properties === null, preg_match(ServiceCheck::STATE_PATTERN, $state) !== 1 => ['outcome' => 'not_checked', 'reason' => ServiceCheck::REASON_SYSTEMCTL_FAILED],
            $properties['LoadState'] === 'not-found' => ['outcome' => 'fail', 'reason' => ServiceCheck::REASON_NOT_FOUND],
            in_array($state, ServiceCheck::PASSING_STATES, true) => ['outcome' => 'pass', 'state' => $state],
            default => ['outcome' => 'fail', 'reason' => ServiceCheck::REASON_INACTIVE, 'state' => $state],
        };
    }
}
