<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * The certificate a hostname served to the daily job.
 */
final class ServedCertificate
{
    /**
     * @param int $expiresAt its last valid second
     * @param bool $trusted whether it was trusted and matched the hostname
     */
    public function __construct(
        public readonly int $expiresAt,
        public readonly bool $trusted,
    ) {
    }
}
