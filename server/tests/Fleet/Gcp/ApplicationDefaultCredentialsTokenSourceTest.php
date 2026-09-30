<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Fleet\Gcp;

use Maguari\Server\Fleet\Gcp\AccessTokenUnavailable;
use Maguari\Server\Fleet\Gcp\ApplicationDefaultCredentialsTokenSource;
use Maguari\Server\Tests\Support\FakeHttpClient;
use Maguari\Server\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class ApplicationDefaultCredentialsTokenSourceTest extends TestCase
{
    private const CLIENT_SECRET = 'client-secret-d41d8c';
    private const REFRESH_TOKEN = 'refresh-token-9e107d';
    private const SERVICE_ACCOUNT = 'maguari@example-project.iam.gserviceaccount.com';

    private string $home;
    private FakeHttpClient $http;
    private FixedClock $clock;
    private ApplicationDefaultCredentialsTokenSource $source;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/maguari-test-home-' . bin2hex(random_bytes(8));
        mkdir($this->home . '/.config/gcloud', 0700, true);
        $this->http = new FakeHttpClient();
        $this->clock = new FixedClock();
        $this->source = new ApplicationDefaultCredentialsTokenSource(
            $this->http,
            $this->clock,
            ApplicationDefaultCredentialsTokenSource::defaultPath($this->home),
        );
    }

    protected function tearDown(): void
    {
        @unlink(ApplicationDefaultCredentialsTokenSource::defaultPath($this->home));
        rmdir($this->home . '/.config/gcloud');
        rmdir($this->home . '/.config');
        rmdir($this->home);
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function writeCredentials(array $credentials): void
    {
        file_put_contents(ApplicationDefaultCredentialsTokenSource::defaultPath($this->home), json_encode($credentials));
    }

    /**
     * @return array<string, string>
     */
    private static function userCredentials(): array
    {
        return [
            'type' => 'authorized_user',
            'client_id' => 'client-id.apps.googleusercontent.com',
            'client_secret' => self::CLIENT_SECRET,
            'refresh_token' => self::REFRESH_TOKEN,
            'quota_project_id' => 'some-quota-project',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function impersonatedCredentials(array $overrides = []): array
    {
        return $overrides + [
            'type' => 'impersonated_service_account',
            'service_account_impersonation_url' => 'https://iamcredentials.googleapis.com/v1/projects/-/serviceAccounts/'
                . self::SERVICE_ACCOUNT . ':generateAccessToken',
            'source_credentials' => self::userCredentials(),
            'delegates' => [],
        ];
    }

    private function assertUnavailable(string $expectedMessage): AccessTokenUnavailable
    {
        try {
            $this->source->accessToken();
            $this->fail('Expected AccessTokenUnavailable.');
        } catch (AccessTokenUnavailable $unavailable) {
            $this->assertStringContainsString($expectedMessage, $unavailable->getMessage());
            $this->assertStringNotContainsString(self::CLIENT_SECRET, $unavailable->getMessage());
            $this->assertStringNotContainsString(self::REFRESH_TOKEN, $unavailable->getMessage());

            return $unavailable;
        }
    }

    public function testRefreshesUserCredentials(): void
    {
        $this->writeCredentials(self::userCredentials());
        $this->http->queueJson(200, ['access_token' => 'ya29.user', 'expires_in' => 3599, 'token_type' => 'Bearer']);

        $token = $this->source->accessToken();

        $this->assertSame('ya29.user', $token->value);
        $this->assertSame($this->clock->now() + 3599, $token->expiresAt);
        $request = $this->http->lastRequest();
        $this->assertSame('POST', $request->method);
        $this->assertSame(ApplicationDefaultCredentialsTokenSource::REFRESH_URL, $request->url);
        $this->assertSame('application/x-www-form-urlencoded', $request->headers['Content-Type']);
        parse_str($request->body, $fields);
        $this->assertSame([
            'client_id' => 'client-id.apps.googleusercontent.com',
            'client_secret' => self::CLIENT_SECRET,
            'refresh_token' => self::REFRESH_TOKEN,
            'grant_type' => 'refresh_token',
        ], $fields);
    }

    public function testImpersonatesTheServiceAccount(): void
    {
        $this->writeCredentials(self::impersonatedCredentials());
        $this->http->queueJson(200, ['access_token' => 'ya29.user', 'expires_in' => 3599]);
        $this->http->queueJson(200, ['accessToken' => 'ya29.impersonated', 'expireTime' => '2026-09-30T13:00:00Z']);

        $token = $this->source->accessToken();

        $this->assertSame('ya29.impersonated', $token->value);
        $this->assertSame(gmmktime(13, 0, 0, 9, 30, 2026), $token->expiresAt);
        $this->assertCount(2, $this->http->requests);
        $impersonation = $this->http->lastRequest();
        $this->assertSame(
            'https://iamcredentials.googleapis.com/v1/projects/-/serviceAccounts/maguari%40example-project.iam.gserviceaccount.com:generateAccessToken',
            $impersonation->url,
        );
        $this->assertSame('Bearer ya29.user', $impersonation->headers['Authorization']);
        $this->assertSame(
            ['scope' => [ApplicationDefaultCredentialsTokenSource::CLOUD_PLATFORM_SCOPE], 'lifetime' => '3600s'],
            json_decode($impersonation->body, true),
        );
    }

    public function testRejectsServiceAccountKeyFiles(): void
    {
        $this->writeCredentials(['type' => 'service_account', 'private_key' => '-----BEGIN PRIVATE KEY-----', 'client_email' => self::SERVICE_ACCOUNT]);

        $this->assertUnavailable('Service account key files are not supported');
        $this->assertSame([], $this->http->requests);
    }

    public function testRejectsImpersonationFromAServiceAccountKey(): void
    {
        $this->writeCredentials(self::impersonatedCredentials([
            'source_credentials' => ['type' => 'service_account', 'private_key' => '-----BEGIN PRIVATE KEY-----'],
        ]));

        $this->assertUnavailable('Service account key files are not supported');
        $this->assertSame([], $this->http->requests);
    }

    public function testRejectsUnknownTypes(): void
    {
        $this->writeCredentials(['type' => 'external_account']);

        $this->assertUnavailable('unsupported type (external_account)');
    }

    public function testRejectsAnUnrecognizedImpersonationUrl(): void
    {
        $this->writeCredentials(self::impersonatedCredentials([
            'service_account_impersonation_url' => 'https://attacker.example/v1/projects/-/serviceAccounts/x@y.com:generateAccessToken',
        ]));

        $this->assertUnavailable('impersonation URL');
        $this->assertSame([], $this->http->requests);
    }

    public function testRejectsDelegates(): void
    {
        $this->writeCredentials(self::impersonatedCredentials(['delegates' => ['other@example-project.iam.gserviceaccount.com']]));

        $this->assertUnavailable('delegates');
    }

    public function testMissingFileSuggestsLoggingIn(): void
    {
        $this->assertUnavailable('Run gcloud auth application-default login');
    }

    public function testInvalidJson(): void
    {
        file_put_contents(ApplicationDefaultCredentialsTokenSource::defaultPath($this->home), '{not json');

        $this->assertUnavailable('not valid JSON');
    }

    public function testIncompleteCredentials(): void
    {
        $this->writeCredentials(['type' => 'authorized_user', 'client_id' => 'x']);

        $this->assertUnavailable('incomplete');
    }

    public function testExpiredLoginSuggestsLoggingInAgain(): void
    {
        $this->writeCredentials(self::userCredentials());
        $this->http->queueJson(400, ['error' => 'invalid_grant', 'error_description' => 'Bad Request']);

        $this->assertUnavailable('expired or was revoked. Run gcloud auth application-default login again.');
    }

    public function testOtherRefreshErrors(): void
    {
        $this->writeCredentials(self::userCredentials());
        $this->http->queueJson(401, ['error' => 'invalid_client']);

        $this->assertUnavailable('HTTP 401');
    }

    public function testUnreachableTokenEndpoint(): void
    {
        $this->writeCredentials(self::userCredentials());
        $this->http->queueFailure('No response from oauth2.googleapis.com: timed out.');

        $this->assertUnavailable('Could not reach Google');
    }

    public function testMissingImpersonationPermission(): void
    {
        $this->writeCredentials(self::impersonatedCredentials());
        $this->http->queueJson(200, ['access_token' => 'ya29.user', 'expires_in' => 3599]);
        $this->http->queueJson(403, ['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED']]);

        $this->assertUnavailable('roles/iam.serviceAccountTokenCreator');
    }

    public function testUnknownServiceAccount(): void
    {
        $this->writeCredentials(self::impersonatedCredentials());
        $this->http->queueJson(200, ['access_token' => 'ya29.user', 'expires_in' => 3599]);
        $this->http->queueJson(404, ['error' => ['code' => 404]]);

        $this->assertUnavailable('does not exist');
    }
}
