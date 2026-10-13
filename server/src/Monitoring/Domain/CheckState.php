<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * The current outcome of one check on one instance and subject (design
 * section 6.3): pass or fail, never "not checked", with the latest sentence
 * for it. Monitoring writes an event only when a result changes the outcome.
 */
final class CheckState
{
    public function __construct(
        public readonly int $instanceId,
        public readonly string $checkName,
        public readonly string $subject,
        public readonly CheckOutcome $outcome,
        public readonly int $since,
        /** The latest result's sentence; null when none was stored. */
        public readonly ?string $detail = null,
    ) {
        if ($outcome === CheckOutcome::NotChecked) {
            throw new \InvalidArgumentException('A check state is pass or fail.');
        }
    }

    /**
     * The state after $result, or null for "not checked", which changes
     * nothing. The same outcome again keeps its time and takes the new
     * sentence.
     */
    public static function after(?self $current, CheckResult $result): ?self
    {
        if ($result->outcome === CheckOutcome::NotChecked) {
            return null;
        }

        $since = $current?->outcome === $result->outcome ? $current->since : $result->checkedAt;

        return new self($result->instanceId, $result->checkName, $result->subject, $result->outcome, $since, $result->detail);
    }

    /**
     * Whether this state has another outcome than $before: a first pass or
     * fail is a change.
     */
    public function changedFrom(?self $before): bool
    {
        return $before?->outcome !== $this->outcome;
    }
}
