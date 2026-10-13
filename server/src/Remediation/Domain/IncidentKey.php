<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Domain;

/**
 * What an incident is about: one check of one instance and one subject
 * (design section 7.4). At most one incident per key is open.
 */
final class IncidentKey
{
    /**
     * @param int $instanceId Fleet's instance ID
     * @param string $subject empty for checks with one result per instance
     */
    public function __construct(
        public readonly int $instanceId,
        public readonly string $checkName,
        public readonly string $subject,
    ) {
    }

    public function equals(self $other): bool
    {
        return $this->instanceId === $other->instanceId && $this->checkName === $other->checkName && $this->subject === $other->subject;
    }
}
