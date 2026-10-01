<?php

declare(strict_types=1);

namespace Maguari\Client;

use Maguari\Shared\ErrorCode;

/**
 * Turns the server's error codes into sentences. The server sends only a code
 * (design section 10.2), so the wording lives here.
 */
final class ServerErrors
{
    public static function failure(TransportResponse $response, int $now): ClientFailure
    {
        $data = $response->json();
        $code = is_string($data['error'] ?? null) ? ErrorCode::tryFrom($data['error']) : null;

        if ($code === null) {
            return new ClientFailure(sprintf('The server answered HTTP %d without a Maguari error code. Check that --server points at Maguari.', $response->status));
        }

        $serverTime = is_int($data['server_time'] ?? null) ? $data['server_time'] : null;

        return new ClientFailure(match ($code) {
            ErrorCode::ClockSkew => $serverTime === null
                ? 'This instance\'s clock is more than 5 minutes off the server\'s. Check time synchronization (timedatectl).'
                : sprintf(
                    'This instance\'s clock is %d seconds %s the server\'s; at most 300 are allowed. Check time synchronization (timedatectl).',
                    abs($now - $serverTime),
                    $now > $serverTime ? 'ahead of' : 'behind',
                ),
            ErrorCode::Unauthorized => 'The server did not accept this client\'s credentials. Enroll the client again.',
            ErrorCode::ReplayedRequest => 'The server had already seen this request\'s nonce. The next request uses a new one.',
            ErrorCode::InvalidToken => 'The enrollment token is unknown, already used or expired. Press Enroll on the server for a new one.',
            ErrorCode::RateLimited => 'The server says this client sends too many requests. It will try again later.',
            ErrorCode::UnsupportedProtocol => 'The server does not support this client\'s protocol version. Upgrade the client or the server.',
            ErrorCode::BadRequest => 'The server rejected the request as malformed. This is a bug in Maguari.',
            ErrorCode::PayloadTooLarge => 'The server rejected the request as too large. This is a bug in Maguari.',
            ErrorCode::NotFound, ErrorCode::MethodNotAllowed => 'The server does not offer this request. Check that --server points at a Maguari server of a compatible version.',
            ErrorCode::ServerError => 'The server had an internal error. Its error log has the details.',
            ErrorCode::Unavailable => 'The server is not ready: it is not set up yet, or its database or secret key is unavailable.',
        });
    }
}
