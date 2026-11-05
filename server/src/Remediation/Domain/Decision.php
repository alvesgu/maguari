<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Domain;

/**
 * What InstanceRemediation::decide() changed, to be stored together in one
 * transaction (design section 3.1 item 7).
 */
final class Decision
{
    /**
     * @param list<Action> $newActions
     * @param list<Action> $changedActions stored actions that got another incident
     * @param list<Incident> $incidents the incidents it changed
     */
    public function __construct(
        public readonly array $newActions = [],
        public readonly array $changedActions = [],
        public readonly array $incidents = [],
    ) {
    }
}
