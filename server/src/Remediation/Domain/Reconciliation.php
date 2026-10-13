<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Domain;

/**
 * What one hourly reconciliation changed. In normal operation both lists are
 * empty: anything here points at a lost event.
 */
final class Reconciliation
{
    /**
     * @param list<Incident> $opened
     * @param list<Incident> $resolved
     */
    public function __construct(
        public readonly int $ranAt,
        public readonly array $opened,
        public readonly array $resolved,
    ) {
    }
}
