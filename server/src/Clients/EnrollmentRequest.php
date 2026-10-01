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
    private const CLIENT_VERSION_PATTERN = '/^[0-9]{1,5}\.[0-9]{1,5}\.[0-9]{1,5}(?:-[0-9A-Za-z.-]{1,32})?$/D';
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
        try {
            $data = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidClientRequest('The body is not valid JSON.');
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidClientRequest('The body is not a JSON object.');
        }

        $protocolVersion = $data['protocol_version'] ?? null;
        $clientVersion = $data['client_version'] ?? null;
        $token = $data['token'] ?? null;

        if (!is_int($protocolVersion)) {
            throw new InvalidClientRequest('protocol_version must be an integer.');
        }

        // Checked before the other fields, whose shape may differ in other
        // protocol versions.
        if (!in_array($protocolVersion, $supportedProtocolVersions, true)) {
            throw new UnsupportedProtocol('This protocol version is not supported.');
        }

        if (!is_string($clientVersion) || preg_match(self::CLIENT_VERSION_PATTERN, $clientVersion) !== 1) {
            throw new InvalidClientRequest('client_version must be a version such as 1.2.3.');
        }

        if (!is_string($token)) {
            throw new InvalidClientRequest('token must be a string.');
        }

        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new InvalidEnrollmentToken('The enrollment token is not valid.');
        }

        return new self($protocolVersion, $clientVersion, $token);
    }
}
