<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Domain;

/**
 * One instance's incidents, for its page.
 */
final class InstanceIncidents
{
    /**
     * @param list<Incident> $open newest first
     * @param list<Incident> $resolved the latest resolved, newest first
     */
    public function __construct(
        public readonly array $open,
        public readonly array $resolved,
    ) {
    }
}
