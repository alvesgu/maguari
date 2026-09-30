<?php

declare(strict_types=1);

namespace Maguari\Server\Fleet\Gcp;

use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\HttpClient\HttpClient;
use Maguari\Server\Kernel\HttpClient\HttpClientFailure;
use Maguari\Server\Kernel\HttpClient\HttpRequest;

/**
 * Production: tokens for the server instance's attached service account, from
 * the metadata server (design section 8 item 2). No key files.
 */
final class MetadataServerTokenSource implements AccessTokenSource
{
    public const TOKEN_URL = 'http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token';

    /** The metadata server is local: anything slower means it is not there. */
    private const TIMEOUT_SECONDS = 2.0;

    private const NOT_AN_INSTANCE = 'The metadata server is not reachable, so Maguari is not running on a Compute Engine instance. '
        . 'For development, set MAGUARI_GCP_CREDENTIALS=application-default.';

    public function __construct(
        private readonly HttpClient $http,
        private readonly Clock $clock,
    ) {
    }

    public function accessToken(): AccessToken
    {
        try {
            $response = $this->http->send(HttpRequest::get(self::TOKEN_URL, ['Metadata-Flavor' => 'Google'], self::TIMEOUT_SECONDS));
        } catch (HttpClientFailure) {
            throw new AccessTokenUnavailable(self::NOT_AN_INSTANCE);
        }

        // Anything else answering on this host name is not the metadata server.
        if ($response->header('Metadata-Flavor') !== 'Google') {
            throw new AccessTokenUnavailable(self::NOT_AN_INSTANCE);
        }

        if ($response->status === 404) {
            throw new AccessTokenUnavailable('The server instance has no service account attached.');
        }

        $data = $response->json();
        $token = $data['access_token'] ?? null;
        $expiresIn = $data['expires_in'] ?? null;

        if (!$response->isSuccessful() || !is_string($token) || $token === '' || !is_int($expiresIn)) {
            throw new AccessTokenUnavailable(sprintf('The metadata server returned an unexpected response (HTTP %d).', $response->status));
        }

        return new AccessToken($token, $this->clock->now() + $expiresIn);
    }
}
