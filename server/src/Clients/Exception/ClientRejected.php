<?php

declare(strict_types=1);

namespace Maguari\Server\Clients\Exception;

use Maguari\Shared\ErrorCode;

/**
 * A signed request that failed verification, with the error code to answer.
 */
final class ClientRejected extends \RuntimeException
{
    /**
     * @param array<string, scalar> $details extra response fields, for example server_time
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        public readonly array $details = [],
    ) {
        parent::__construct('Client request rejected: ' . $errorCode->value);
    }
}
