<?php

declare(strict_types=1);

namespace Maguari\Server\Fleet\Gcp;

use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\HttpClient\HttpClient;
use Maguari\Server\Kernel\HttpClient\HttpClientFailure;
use Maguari\Server\Kernel\HttpClient\HttpRequest;
use Maguari\Server\Kernel\HttpClient\HttpResponse;

/**
 * Development only: tokens from the developer's own gcloud login, the file
 * written by "gcloud auth application-default login" (optionally with
 * --impersonate-service-account). Service account key files are rejected, at
 * the top level and as the source of an impersonation, so key files can never
 * be used through this class.
 */
final class ApplicationDefaultCredentialsTokenSource implements AccessTokenSource
{
    public const REFRESH_URL = 'https://oauth2.googleapis.com/token';
    public const CLOUD_PLATFORM_SCOPE = 'https://www.googleapis.com/auth/cloud-platform';

    private const IMPERSONATION_URL_PATTERN
        = '#^https://iamcredentials\.googleapis\.com/v1/projects/-/serviceAccounts/([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+):generateAccessToken$#';

    private const LOG_IN_AGAIN = 'Run gcloud auth application-default login again.';

    private const KEY_FILES_NOT_SUPPORTED = 'Service account key files are not supported. '
        . 'Run gcloud auth application-default login, optionally with --impersonate-service-account.';

    public function __construct(
        private readonly HttpClient $http,
        private readonly Clock $clock,
        private readonly string $credentialsFile,
    ) {
    }

    /**
     * Where gcloud writes Application Default Credentials. GOOGLE_APPLICATION_CREDENTIALS
     * is deliberately not honored: it is the usual way to point at a key file.
     */
    public static function defaultPath(string $home): string
    {
        return rtrim($home, '/') . '/.config/gcloud/application_default_credentials.json';
    }

    public function accessToken(): AccessToken
    {
        $credentials = $this->readCredentials();

        return match ($credentials['type'] ?? null) {
            'authorized_user' => $this->refresh($credentials),
            'impersonated_service_account' => $this->impersonatedToken($credentials),
            'service_account' => throw new AccessTokenUnavailable(self::KEY_FILES_NOT_SUPPORTED),
            default => throw new AccessTokenUnavailable(sprintf(
                'The credentials in %s have an unsupported type (%s). Run gcloud auth application-default login.',
                $this->credentialsFile,
                self::describeType($credentials['type'] ?? null),
            )),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function readCredentials(): array
    {
        if (!is_file($this->credentialsFile) || !is_readable($this->credentialsFile)) {
            throw new AccessTokenUnavailable(sprintf(
                'No Application Default Credentials found at %s. Run gcloud auth application-default login.',
                $this->credentialsFile,
            ));
        }

        try {
            $data = json_decode((string) file_get_contents($this->credentialsFile), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }

        if (!is_array($data)) {
            throw new AccessTokenUnavailable(sprintf('%s is not valid JSON. %s', $this->credentialsFile, self::LOG_IN_AGAIN));
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function impersonatedToken(#[\SensitiveParameter] array $credentials): AccessToken
    {
        $source = $credentials['source_credentials'] ?? null;
        $sourceType = is_array($source) ? ($source['type'] ?? null) : null;

        if ($sourceType === 'service_account') {
            throw new AccessTokenUnavailable(self::KEY_FILES_NOT_SUPPORTED);
        }

        if ($sourceType !== 'authorized_user') {
            throw new AccessTokenUnavailable(sprintf(
                'The impersonation in %s uses unsupported source credentials (%s). %s',
                $this->credentialsFile,
                self::describeType($sourceType),
                self::LOG_IN_AGAIN,
            ));
        }

        $url = $credentials['service_account_impersonation_url'] ?? null;

        if (!is_string($url) || preg_match(self::IMPERSONATION_URL_PATTERN, $url, $matches) !== 1) {
            throw new AccessTokenUnavailable(sprintf(
                'The impersonation URL in %s is missing or not recognized. %s',
                $this->credentialsFile,
                self::LOG_IN_AGAIN,
            ));
        }

        if (($credentials['delegates'] ?? []) !== []) {
            throw new AccessTokenUnavailable('Impersonation through delegates is not supported. Impersonate the service account directly.');
        }

        return $this->impersonate($this->refresh($source), rawurldecode($matches[1]));
    }

    /**
     * Exchanges the refresh token of a gcloud user login for an access token.
     *
     * @param array<string, mixed> $credentials
     */
    private function refresh(#[\SensitiveParameter] array $credentials): AccessToken
    {
        $fields = [];

        foreach (['client_id', 'client_secret', 'refresh_token'] as $key) {
            $value = $credentials[$key] ?? null;

            if (!is_string($value) || $value === '') {
                throw new AccessTokenUnavailable(sprintf('%s is incomplete. %s', $this->credentialsFile, self::LOG_IN_AGAIN));
            }

            $fields[$key] = $value;
        }

        $response = $this->send(HttpRequest::postForm(self::REFRESH_URL, $fields + ['grant_type' => 'refresh_token']));
        $data = $response->json();

        if (!$response->isSuccessful()) {
            if (($data['error'] ?? null) === 'invalid_grant') {
                throw new AccessTokenUnavailable('Your gcloud application-default login has expired or was revoked. ' . self::LOG_IN_AGAIN);
            }

            throw new AccessTokenUnavailable(sprintf(
                'Google did not accept your gcloud application-default login (HTTP %d). %s',
                $response->status,
                self::LOG_IN_AGAIN,
            ));
        }

        $token = $data['access_token'] ?? null;
        $expiresIn = $data['expires_in'] ?? null;

        if (!is_string($token) || $token === '' || !is_int($expiresIn)) {
            throw new AccessTokenUnavailable('Google returned an unexpected response when refreshing your gcloud login.');
        }

        return new AccessToken($token, $this->clock->now() + $expiresIn);
    }

    private function impersonate(AccessToken $sourceToken, string $serviceAccount): AccessToken
    {
        $response = $this->send(HttpRequest::postJson(
            sprintf('https://iamcredentials.googleapis.com/v1/projects/-/serviceAccounts/%s:generateAccessToken', rawurlencode($serviceAccount)),
            ['scope' => [self::CLOUD_PLATFORM_SCOPE], 'lifetime' => '3600s'],
            ['Authorization' => 'Bearer ' . $sourceToken->value],
        ));

        if ($response->status === 403) {
            throw new AccessTokenUnavailable(sprintf(
                'Your account cannot impersonate %s. Grant it roles/iam.serviceAccountTokenCreator on that service account.',
                $serviceAccount,
            ));
        }

        if ($response->status === 404) {
            throw new AccessTokenUnavailable(sprintf('The service account %s does not exist.', $serviceAccount));
        }

        $data = $response->json();
        $token = $data['accessToken'] ?? null;
        $expiresAt = self::parseTime($data['expireTime'] ?? null);

        if (!$response->isSuccessful() || !is_string($token) || $token === '' || $expiresAt === null) {
            throw new AccessTokenUnavailable(sprintf(
                'Google returned an unexpected response when impersonating %s (HTTP %d).',
                $serviceAccount,
                $response->status,
            ));
        }

        return new AccessToken($token, $expiresAt);
    }

    private function send(HttpRequest $request): HttpResponse
    {
        try {
            return $this->http->send($request);
        } catch (HttpClientFailure $failure) {
            throw new AccessTokenUnavailable('Could not reach Google to obtain a token. ' . $failure->getMessage());
        }
    }

    /**
     * An RFC 3339 time such as 2026-09-30T12:00:00Z as Unix seconds.
     */
    private static function parseTime(mixed $value): ?int
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * The type name for a message. The file is untrusted, so anything unusual is
     * not echoed.
     */
    private static function describeType(mixed $type): string
    {
        return is_string($type) && preg_match('/^[a-z_]{1,40}$/', $type) === 1 ? $type : 'missing or unknown';
    }
}
