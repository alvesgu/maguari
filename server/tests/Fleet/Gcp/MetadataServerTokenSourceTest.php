<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Fleet\Gcp;

use Maguari\Server\Fleet\Gcp\AccessTokenUnavailable;
use Maguari\Server\Fleet\Gcp\MetadataServerTokenSource;
use Maguari\Server\Kernel\HttpClient\HttpResponse;
use Maguari\Server\Tests\Support\FakeHttpClient;
use Maguari\Server\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class MetadataServerTokenSourceTest extends TestCase
{
    private FakeHttpClient $http;
    private FixedClock $clock;
    private MetadataServerTokenSource $source;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->clock = new FixedClock();
        $this->source = new MetadataServerTokenSource($this->http, $this->clock);
    }

    private function assertUnavailable(string $expectedMessage): void
    {
        try {
            $this->source->accessToken();
            $this->fail('Expected AccessTokenUnavailable.');
        } catch (AccessTokenUnavailable $unavailable) {
            $this->assertStringContainsString($expectedMessage, $unavailable->getMessage());
        }
    }

    public function testReturnsTheToken(): void
    {
        $this->http->queueJson(200, ['access_token' => 'ya29.token', 'expires_in' => 3599, 'token_type' => 'Bearer'], ['Metadata-Flavor' => 'Google']);

        $token = $this->source->accessToken();

        $this->assertSame('ya29.token', $token->value);
        $this->assertSame($this->clock->now() + 3599, $token->expiresAt);
        $request = $this->http->lastRequest();
        $this->assertSame('GET', $request->method);
        $this->assertSame(MetadataServerTokenSource::TOKEN_URL, $request->url);
        $this->assertSame('Google', $request->headers['Metadata-Flavor']);
        $this->assertLessThanOrEqual(2.0, $request->timeoutSeconds);
    }

    public function testUnreachableMetadataServerSuggestsDevelopmentCredentials(): void
    {
        $this->http->queueFailure();

        $this->assertUnavailable('MAGUARI_GCP_CREDENTIALS=application-default');
    }

    public function testResponseWithoutTheMetadataFlavorHeaderIsRejected(): void
    {
        $this->http->queueJson(200, ['access_token' => 'not-from-metadata', 'expires_in' => 3599]);

        $this->assertUnavailable('metadata server is not reachable');
    }

    public function testNoServiceAccount(): void
    {
        $this->http->queue(new HttpResponse(404, ['Metadata-Flavor' => 'Google'], 'Not Found'));

        $this->assertUnavailable('no service account attached');
    }

    public function testUnexpectedResponse(): void
    {
        $this->http->queueJson(500, ['error' => 'x'], ['Metadata-Flavor' => 'Google']);

        $this->assertUnavailable('HTTP 500');
    }

    public function testTokenIsHiddenFromDebugOutput(): void
    {
        $this->http->queueJson(200, ['access_token' => 'ya29.secret', 'expires_in' => 3599], ['Metadata-Flavor' => 'Google']);

        $token = $this->source->accessToken();

        $this->assertStringNotContainsString('ya29.secret', print_r($token, true));
    }
}
