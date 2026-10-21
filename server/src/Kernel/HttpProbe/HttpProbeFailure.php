<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\HttpProbe;

final class HttpProbeFailure extends \RuntimeException
{
    public function __construct(
        public readonly HttpProbeFailureKind $kind,
        string $message,
    ) {
        parent::__construct($message);
    }
}
