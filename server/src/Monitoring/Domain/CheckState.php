<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * The current outcome of one check on one instance and subject (design
 * section 6.3): pass or fail, never "not checked". Monitoring writes an event
 * only when a result changes it.
 */
final class CheckState
{
    public function __construct(
        public readonly int $instanceId,
        public readonly string $checkName,
        public readonly string $subject,
        public readonly CheckOutcome $outcome,
        public readonly int $since,
    ) {
        if ($outcome === CheckOutcome::NotChecked) {
            throw new \InvalidArgumentException('A check state is pass or fail.');
        }
    }

    /**
     * The state after $result, or null when $result changes nothing: "not
     * checked", or the same outcome again. A first pass or fail is a change.
     */
    public static function after(?self $current, CheckResult $result): ?self
    {
        if ($result->outcome === CheckOutcome::NotChecked || $current?->outcome === $result->outcome) {
            return null;
        }

        return new self($result->instanceId, $result->checkName, $result->subject, $result->outcome, $result->checkedAt);
    }
}
