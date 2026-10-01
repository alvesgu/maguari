<?php

declare(strict_types=1);

namespace Maguari\Server\Clients;

use Maguari\Server\Clients\Exception\InvalidClientRequest;
use Maguari\Server\Clients\Exception\UnsupportedProtocol;

/**
 * The checks every client request body shares. Unknown fields are ignored, so
 * later clients can add optional ones (design section 4 item 4).
 */
final class RequestFields
{
    private const CLIENT_VERSION_PATTERN = '/^[0-9]{1,5}\.[0-9]{1,5}\.[0-9]{1,5}(?:-[0-9A-Za-z.-]{1,32})?$/D';

    /**
     * @return array<string, mixed>
     * @throws InvalidClientRequest
     */
    public static function decodeObject(string $body): array
    {
        try {
            $data = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidClientRequest('The body is not valid JSON.');
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidClientRequest('The body is not a JSON object.');
        }

        return $data;
    }

    /**
     * Checked before other fields, whose shape may differ between protocol
     * versions.
     *
     * @param array<string, mixed> $data
     * @param int[] $supported
     * @throws InvalidClientRequest
     * @throws UnsupportedProtocol
     */
    public static function protocolVersion(array $data, array $supported): int
    {
        $version = $data['protocol_version'] ?? null;

        if (!is_int($version)) {
            throw new InvalidClientRequest('protocol_version must be an integer.');
        }

        if (!in_array($version, $supported, true)) {
            throw new UnsupportedProtocol('This protocol version is not supported.');
        }

        return $version;
    }

    /**
     * @param array<string, mixed> $data
     * @throws InvalidClientRequest
     */
    public static function clientVersion(array $data): string
    {
        $version = $data['client_version'] ?? null;

        if (!is_string($version) || preg_match(self::CLIENT_VERSION_PATTERN, $version) !== 1) {
            throw new InvalidClientRequest('client_version must be a version such as 1.2.3.');
        }

        return $version;
    }
}
