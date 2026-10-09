<?php

declare(strict_types=1);

namespace Maguari\Server\Http;

use Maguari\Shared\ErrorCode;
use Psr\Http\Message\ResponseInterface;

/**
 * The JSON responses under /api/client/. Errors are {"error": "<code>"}, with
 * no human-readable message: the client logs its own sentence per code.
 */
final class ClientApiResponse
{
    /**
     * @param array<string, scalar> $extra more fields, for example server_time
     */
    public static function error(ResponseInterface $response, ErrorCode $code, array $extra = []): ResponseInterface
    {
        return self::json($response->withStatus(self::status($code)), ['error' => $code->value] + $extra);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(ResponseInterface $response, array $data): ResponseInterface
    {
        return JsonResponse::write($response, $data);
    }

    public static function status(ErrorCode $code): int
    {
        return match ($code) {
            ErrorCode::BadRequest, ErrorCode::UnsupportedProtocol => 400,
            ErrorCode::Unauthorized, ErrorCode::ClockSkew, ErrorCode::ReplayedRequest, ErrorCode::InvalidToken => 401,
            ErrorCode::NotFound => 404,
            ErrorCode::MethodNotAllowed => 405,
            ErrorCode::PayloadTooLarge => 413,
            ErrorCode::RateLimited => 429,
            ErrorCode::ServerError => 500,
            ErrorCode::Unavailable => 503,
        };
    }
}
