<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * A remote HTTP check with its current outcome, for pages.
 */
final class HttpCheckStatus
{
    /**
     * @param ?CheckState $state null until its first result
     */
    public function __construct(
        public readonly HttpCheck $check,
        public readonly ?CheckState $state,
    ) {
    }

    /**
     * Not checked until the first result.
     */
    public function outcome(): CheckOutcome
    {
        return $this->state?->outcome ?? CheckOutcome::NotChecked;
    }

    public function detail(): string
    {
        return $this->state?->detail ?? 'Not checked yet.';
    }
}
