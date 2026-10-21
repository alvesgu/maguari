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
     */
    public function __construct(
        public readonly ClientCheck $check,
        public readonly ?CheckState $state,
        public readonly int $failures = 0,
    ) {
    }

    public function outcome(): CheckOutcome
    {
        return $this->state?->outcome ?? CheckOutcome::NotChecked;
    }

    public function detail(): string
    {
        $detail = $this->state?->detail ?? 'Not checked yet.';

        if ($this->failures > 0) {
            $detail .= sprintf(' The latest result failed; it counts after %d in a row.', ConfirmedFailures::FAILURES_TO_FAIL);
        }

        return $detail;
    }
}
