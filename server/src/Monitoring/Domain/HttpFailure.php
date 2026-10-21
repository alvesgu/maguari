<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Domain;

/**
 * Why a remote HTTP check's request got no status.
 */
enum HttpFailure
{
    case NameNotResolved;
    case Refused;
    case TimedOut;
    case TlsFailed;
    case ConnectFailed;
    case BadResponse;
}
