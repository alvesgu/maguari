<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * Remote HTTP checks above their egress budget (a warning) or limit (a refusal).
 */
final class EgressExcess
{
    public function __construct(
        public readonly bool $refused,
        public readonly string $sentence,
    ) {
    }
}
