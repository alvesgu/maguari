<?php

declare(strict_types=1);

namespace Maguari\Server\Http;

/**
 * Error codes under /admin/api/ (design section 10.2). The server's own, not
 * the client protocol's ErrorCode: the web app is not a client. Where a
 * meaning is the same, so is the string.
 */
enum AdminApiError: string
{
    case BadRequest = 'bad_request';
    case Unauthorized = 'unauthorized';
    case NotFound = 'not_found';
    case MethodNotAllowed = 'method_not_allowed';
    case ServerError = 'server_error';
    case Unavailable = 'unavailable';

    public function status(): int
    {
        return match ($this) {
            self::BadRequest => 400,
            self::Unauthorized => 401,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::ServerError => 500,
            self::Unavailable => 503,
        };
    }
}
