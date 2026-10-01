<?php

declare(strict_types=1);

namespace Maguari\Server\Clients;

use Maguari\Server\Clients\Exception\InvalidClientRequest;
use Maguari\Server\Clients\Exception\InvalidEnrollmentToken;
use Maguari\Server\Clients\Exception\UnsupportedProtocol;

/**
 * The body of POST /api/client/enroll:
 * {"protocol_version": 1, "client_version": "0.1.0", "token": "..."}.
 * Unknown fields are ignored, so later clients can add optional ones.
 */
final class EnrollmentRequest
{
    // 32 random bytes in base64url without padding, as EnrollmentTokens issues them.
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/D';

    private function __construct(
        public readonly int $protocolVersion,
        public readonly string $clientVersion,
        #[\SensitiveParameter]
        public readonly string $token,
    ) {
    }

    /**
     * @param int[] $supportedProtocolVersions
     * @throws InvalidClientRequest
     * @throws UnsupportedProtocol
     * @throws InvalidEnrollmentToken when the token cannot be one Maguari issued
     */
    public static function parse(string $body, array $supportedProtocolVersions): self
    {
        $data = RequestFields::decodeObject($body);
        $protocolVersion = RequestFields::protocolVersion($data, $supportedProtocolVersions);
        $clientVersion = RequestFields::clientVersion($data);
        $token = $data['token'] ?? null;

        if (!is_string($token)) {
            throw new InvalidClientRequest('token must be a string.');
        }

        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new InvalidEnrollmentToken('The enrollment token is not valid.');
        }

        return new self($protocolVersion, $clientVersion, $token);
    }
}
