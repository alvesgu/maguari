<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * A remote HTTP check as saved, with the warning when all checks together
 * are now above the egress budget.
 */
final class HttpCheckSaved
{
    public function __construct(
        public readonly HttpCheck $check,
        public readonly ?string $warning = null,
    ) {
    }
}
