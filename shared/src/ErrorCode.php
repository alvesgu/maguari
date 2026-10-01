<?php

declare(strict_types=1);

namespace Maguari\Shared;

/**
 * The error codes in the server's JSON error responses under /api/client/:
 * {"error": "<code>"}. Fixed strings the client can act on; the server sends
 * no human-readable message.
 */
enum ErrorCode: string
{
    case BadRequest = 'bad_request';
    case UnsupportedProtocol = 'unsupported_protocol';
    case Unauthorized = 'unauthorized';
    case ClockSkew = 'clock_skew';
    case ReplayedRequest = 'replayed_request';
    case InvalidToken = 'invalid_token';
    case NotFound = 'not_found';
    case MethodNotAllowed = 'method_not_allowed';
    case PayloadTooLarge = 'payload_too_large';
    case RateLimited = 'rate_limited';
    case ServerError = 'server_error';
    case Unavailable = 'unavailable';
}
