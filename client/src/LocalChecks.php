<?php

declare(strict_types=1);

namespace Maguari\Client;

use Maguari\Shared\LocalHttpCheck;
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
        private readonly LocalHttp $localHttp = new StreamLocalHttp(),
    ) {
    }

    /**
     * @return list<array<string, string|int>>
     */
    public function run(?CheckSettings $settings): array
    {
        $start = $this->clock->now();
        $results = [];

        foreach ($settings?->checks ?? [] as $check) {
            $subject = $check['type'] === LocalHttpCheck::TYPE ? LocalHttpCheck::subject($check['url'], $check['host_name']) : $check['unit'];

            if ($this->clock->now() - $start >= self::TIME_LIMIT_SECONDS) {
                $results[] = ['type' => $check['type'], 'subject' => $subject, 'outcome' => 'not_checked', 'reason' => Protocol::CHECK_REASON_TIME_LIMIT];

                continue;
            }

            $results[] = $check['type'] === LocalHttpCheck::TYPE ? $this->localHttp($check['url'], $check['host_name'], $subject) : $this->service($check['unit']);
        }

        return $results;
    }

    /**
     * @return array<string, string|int>
     */
    private function localHttp(string $url, string $hostName, string $subject): array
    {
        $answer = $this->localHttp->request($url, $hostName);
        $result = ['type' => LocalHttpCheck::TYPE, 'subject' => $subject];

        if (is_string($answer)) {
            return $result + ['outcome' => 'fail', 'reason' => $answer];
        }

        return $result + ['outcome' => $answer['status'] >= 200 && $answer['status'] < 400 ? 'pass' : 'fail', 'status' => $answer['status'], 'ms' => $answer['ms']];
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
