<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * One result a client reported for one of its checks (design section 5.2),
 * validated: only codes and values, never a sentence.
 */
final class ClientCheckResult
{
    /**
     * @param ?string $reason why it failed or was not checked; null for a pass
     * @param ?string $state the service's ActiveState, when the client read one
     * @param ?int $status a local HTTP check's status code, when one arrived
     * @param ?int $elapsedMs and the time until it arrived
     */
    public function __construct(
        public readonly string $type,
        public readonly string $subject,
        public readonly CheckOutcome $outcome,
        public readonly ?string $reason = null,
        public readonly ?string $state = null,
        public readonly ?int $status = null,
        public readonly ?int $elapsedMs = null,
    ) {
    }
}
