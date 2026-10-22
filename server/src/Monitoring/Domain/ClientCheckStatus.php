<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * A client check with its current outcome, for pages.
 */
final class ClientCheckStatus
{
    /**
     * @param ?CheckState $state null until its first result counts
     * @param int $failures failed results in a row that do not count yet
     * @param ?CheckResult $notChecked the latest result when it was "not
     *        checked", which leaves the outcome as it was
     */
    public function __construct(
        public readonly ClientCheck $check,
        public readonly ?CheckState $state,
        public readonly int $failures = 0,
        public readonly ?CheckResult $notChecked = null,
    ) {
    }

    public function outcome(): CheckOutcome
    {
        return $this->state?->outcome ?? CheckOutcome::NotChecked;
    }

    public function detail(): string
    {
        $detail = $this->state?->detail ?? $this->notChecked?->detail ?? 'Not checked yet.';

        if ($this->failures > 0) {
            $detail .= sprintf(' %s; it counts after %d in a row.', $this->notChecked === null ? 'The latest result failed' : 'The latest result that ran failed', ConfirmedFailures::FAILURES_TO_FAIL);
        }

        if ($this->state !== null && $this->notChecked !== null) {
            $detail .= ' The latest result was not checked. ' . $this->notChecked->detail;
        }

        return $detail;
    }

    /**
     * The latest result's time, "not checked" included; null before any.
     */
    public function checkedAt(): ?int
    {
        return $this->notChecked?->checkedAt ?? $this->state?->checkedAt;
    }
}
