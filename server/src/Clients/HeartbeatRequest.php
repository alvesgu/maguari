<?php

declare(strict_types=1);

namespace Maguari\Server\Clients;

use Maguari\Server\Clients\Exception\InvalidClientRequest;
use Maguari\Server\Clients\Exception\UnsupportedProtocol;
use Maguari\Shared\Protocol;

/**
 * The body of POST /api/client/heartbeat (design section 5.2). readings,
 * checks and command_results are optional lists. The readings and checks are
 * kept as decoded, for Monitoring to validate; command_results are accepted
 * but not used until later steps. check_settings_version is optional: a
 * client that sends it, even as null, understands check_settings.
 */
final class HeartbeatRequest
{
    private const LISTS = ['readings', 'checks', 'command_results'];

    private function __construct(
        public readonly int $protocolVersion,
        public readonly string $clientVersion,
        public readonly int $sentAt,
        /** @var list<mixed> */
        public readonly array $readings,
        /** @var list<mixed> */
        public readonly array $checks,
        public readonly ReportedCheckSettings $checkSettings,
    ) {
    }

    /**
     * @param string $clientId the authenticated client, which the body's client_id must name
     * @param int[] $supportedProtocolVersions
     * @throws InvalidClientRequest
     * @throws UnsupportedProtocol
     */
    public static function parse(string $body, string $clientId, array $supportedProtocolVersions): self
    {
        $data = RequestFields::decodeObject($body);
        $protocolVersion = RequestFields::protocolVersion($data, $supportedProtocolVersions);
        $clientVersion = RequestFields::clientVersion($data);

        if (($data['client_id'] ?? null) !== $clientId) {
            throw new InvalidClientRequest('client_id must match the X-Maguari-Client header.');
        }

        $sentAt = $data['sent_at'] ?? null;

        if (!is_int($sentAt) || $sentAt < 0) {
            throw new InvalidClientRequest('sent_at must be Unix seconds.');
        }

        foreach (self::LISTS as $field) {
            if (array_key_exists($field, $data) && (!is_array($data[$field]) || !array_is_list($data[$field]))) {
                throw new InvalidClientRequest($field . ' must be a list.');
            }
        }

        $supported = array_key_exists('check_settings_version', $data);
        $version = $data['check_settings_version'] ?? null;

        if ($version !== null && (!is_string($version) || preg_match(Protocol::CHECK_SETTINGS_VERSION_PATTERN, $version) !== 1)) {
            throw new InvalidClientRequest('check_settings_version must be null or 16 lowercase hex characters.');
        }

        return new self($protocolVersion, $clientVersion, $sentAt, $data['readings'] ?? [], $data['checks'] ?? [], new ReportedCheckSettings($supported, $version));
    }
}
