<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\HttpProbe;

/**
 * Why no status line arrived, told apart so callers can word each case.
 */
enum HttpProbeFailureKind
{
    case NameNotResolved;
    case Refused;
    case TimedOut;
    /** The handshake failed, the certificate was not trusted or did not match the name. */
    case TlsFailed;
    /** Any other connection failure. */
    case ConnectFailed;
    /** The server answered something that is not an HTTP status line, or closed first. */
    case BadResponse;
}
