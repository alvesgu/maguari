<?php

declare(strict_types=1);

namespace Maguari\Client;

use Maguari\Shared\Protocol;
use Maguari\Shared\ServerUrl;

/**
 * Exchanges a one-time enrollment token for permanent credentials (design
 * section 5.6 item 3).
 */
final class Enroller
{
    public function __construct(
        private readonly Transport $transport,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @throws ClientFailure
     */
    public function enroll(string $server, #[\SensitiveParameter] string $token): Credentials
    {
        // The same rule as the server's issue-setup-token: the secret comes
        // back in the answer, so plain HTTP is allowed only on localhost.
        $serverUrl = ServerUrl::normalize($server) ?? throw new ClientFailure(
            '--server must be an https:// URL with no path, for example https://maguari.example.com. '
                . 'http:// is accepted only for localhost.',
        );

        $response = $this->transport->post($serverUrl . Protocol::ENROLL_PATH, [], json_encode([
            'protocol_version' => Protocol::VERSION,
            'client_version' => Version::current(),
            'token' => $token,
        ], JSON_THROW_ON_ERROR));

        if ($response->status !== 200) {
            throw ServerErrors::failure($response, $this->clock->now());
        }

        $data = $response->json();
        $clientId = $data['client_id'] ?? null;
        $secret = is_string($data['secret'] ?? null) ? CredentialsFile::decodeSecret($data['secret']) : null;

        if (!is_string($clientId) || preg_match('/^[0-9a-f]{32}$/D', $clientId) !== 1 || $secret === null) {
            throw new ClientFailure('The server\'s enrollment answer is not valid. Check that --server points at Maguari.');
        }

        return new Credentials($serverUrl, $clientId, $secret);
    }
}
