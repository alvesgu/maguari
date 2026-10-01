<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Http;

use Maguari\Server\Clients\ClientsApi;
use Maguari\Server\Clients\EnrolledClient;
use Maguari\Server\Clients\HeartbeatState;
use Maguari\Server\Tests\Support\TestEnvironment;
use Maguari\Shared\Signature;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Signed heartbeats through the whole app (design sections 5.2, 5.5 and 10.2).
 */
final class HeartbeatFlowTest extends TestCase
{
    private const PATH = '/api/client/heartbeat';

    private TestEnvironment $environment;
    private EnrolledClient $client;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
        $this->client = $this->environment->enrollClient(7);
    }

    protected function tearDown(): void
    {
        $this->environment->cleanUp();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function body(EnrolledClient $client, array $overrides = []): string
    {
        return json_encode($overrides + [
            'protocol_version' => 1,
            'client_version' => '0.1.0',
            'client_id' => $client->clientId,
            'sent_at' => $this->environment->clock->now(),
            'readings' => [],
            'checks' => [],
            'command_results' => [],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Signs and sends a heartbeat. $headers replaces or (with null) removes
     * signature headers after signing.
     *
     * @param array<string, string|null> $headers
     */
    private function send(
        ?EnrolledClient $client = null,
        ?string $body = null,
        ?int $timestamp = null,
        ?string $nonce = null,
        array $headers = [],
        string $uri = self::PATH,
        ?string $signedPath = null,
        ?string $signedBody = null,
    ): ResponseInterface {
        $client ??= $this->client;
        $body ??= $this->body($client);
        $timestamp = (string) ($timestamp ?? $this->environment->clock->now());
        $nonce ??= Signature::newNonce();
        $signature = Signature::sign($client->secret, 'POST', $signedPath ?? self::PATH, $timestamp, $nonce, $signedBody ?? $body);
        $headers += [
            'X-Maguari-Client' => $client->clientId,
            'X-Maguari-Timestamp' => $timestamp,
            'X-Maguari-Nonce' => $nonce,
            'X-Maguari-Signature' => $signature,
        ];
        $request = (new ServerRequestFactory())->createServerRequest('POST', $uri)
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream($body));

        foreach ($headers as $name => $value) {
            if ($value !== null) {
                $request = $request->withHeader($name, $value);
            }
        }

        return $this->environment->app()->handle($request);
    }

    private function assertError(ResponseInterface $response, int $status, string $code): void
    {
        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame($code, json_decode((string) $response->getBody(), true)['error']);
    }

    private function nonceCount(): int
    {
        return (int) $this->environment->database->pdo()->query('SELECT COUNT(*) FROM clients_nonces')->fetchColumn();
    }

    private function lastHeartbeatAt(): ?int
    {
        $statement = $this->environment->database->pdo()->prepare('SELECT last_heartbeat_at FROM clients_clients WHERE client_id = ?');
        $statement->execute([$this->client->clientId]);
        $value = $statement->fetchColumn();

        return $value === null ? null : (int) $value;
    }

    public function testAcceptsASignedHeartbeat(): void
    {
        $this->environment->clock->advance(5);

        $response = $this->send(body: $this->body($this->client, ['sent_at' => 1, 'client_version' => '0.2.0']));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        // The server's clock, not the client's sent_at.
        $this->assertSame($this->environment->clock->now(), $this->lastHeartbeatAt());
        $status = $this->environment->clients->heartbeatStatuses([7])[7];
        $this->assertSame(HeartbeatState::OnTime, $status->state);
        $this->assertSame('0.2.0', $status->clientVersion);
        $this->assertSame(1, $this->nonceCount());
    }

    /**
     * @return array<string, array{array<string, string|null>}>
     */
    public static function badHeaders(): array
    {
        return [
            'no client' => [['X-Maguari-Client' => null]],
            'no timestamp' => [['X-Maguari-Timestamp' => null]],
            'no nonce' => [['X-Maguari-Nonce' => null]],
            'no signature' => [['X-Maguari-Signature' => null]],
            'uppercase signature' => [['X-Maguari-Signature' => str_repeat('A', 64)]],
            'short signature' => [['X-Maguari-Signature' => str_repeat('a', 63)]],
            'short nonce' => [['X-Maguari-Nonce' => str_repeat('a', 31)]],
            'timestamp with a sign' => [['X-Maguari-Timestamp' => '+1790000000']],
            'timestamp with a fraction' => [['X-Maguari-Timestamp' => '1790000000.5']],
            'unknown client' => [['X-Maguari-Client' => str_repeat('0', 32)]],
            'malformed client' => [['X-Maguari-Client' => 'client-1']],
            'wrong signature' => [['X-Maguari-Signature' => str_repeat('0', 64)]],
        ];
    }

    /**
     * @dataProvider badHeaders
     * @param array<string, string|null> $headers
     */
    public function testRejectsBadHeadersWithoutWritingAnything(array $headers): void
    {
        $this->assertError($this->send(headers: $headers), 401, 'unauthorized');
        $this->assertSame(0, $this->nonceCount());
        $this->assertNull($this->lastHeartbeatAt());
    }

    public function testRejectsAnotherClientsSecret(): void
    {
        $other = $this->environment->enrollClient(8);
        $impostor = new EnrolledClient($this->client->clientId, $other->secret);

        $this->assertError($this->send($impostor, $this->body($this->client)), 401, 'unauthorized');
    }

    public function testRejectsABodyChangedAfterSigning(): void
    {
        $signed = $this->body($this->client);

        $this->assertError($this->send(body: $this->body($this->client, ['sent_at' => 1]), signedBody: $signed), 401, 'unauthorized');
        $this->assertSame(0, $this->nonceCount());
    }

    public function testRejectsASignatureForAnotherPath(): void
    {
        $this->assertError($this->send(signedPath: '/api/client/enroll'), 401, 'unauthorized');
    }

    public function testRejectsAQueryString(): void
    {
        $this->assertError($this->send(uri: self::PATH . '?debug=1'), 400, 'bad_request');
        $this->assertSame(0, $this->nonceCount());
    }

    public function testClockWindow(): void
    {
        $now = $this->environment->clock->now();

        foreach ([-301, 301] as $offset) {
            $response = $this->send(timestamp: $now + $offset);

            $this->assertError($response, 401, 'clock_skew');
            $this->assertSame($now, json_decode((string) $response->getBody(), true)['server_time']);
        }

        $this->assertSame(0, $this->nonceCount());
        $this->assertSame(204, $this->send(timestamp: $now - 300)->getStatusCode());
        $this->assertSame(204, $this->send(timestamp: $now + 300)->getStatusCode());
    }

    public function testRejectsAReplayedNonce(): void
    {
        $nonce = Signature::newNonce();
        $this->assertSame(204, $this->send(nonce: $nonce)->getStatusCode());
        $this->environment->clock->advance(10);

        // A different body and timestamp, but the same nonce.
        $this->assertError($this->send(nonce: $nonce), 401, 'replayed_request');
        $this->assertSame(1, $this->nonceCount());
    }

    public function testNoncesAreScopedPerClient(): void
    {
        $other = $this->environment->enrollClient(8);
        $nonce = Signature::newNonce();

        $this->assertSame(204, $this->send(nonce: $nonce)->getStatusCode());
        $this->assertSame(204, $this->send($other, nonce: $nonce)->getStatusCode());
    }

    public function testRateLimit(): void
    {
        for ($i = 0; $i < ClientsApi::RATE_LIMIT_REQUESTS; $i++) {
            $this->assertSame(204, $this->send()->getStatusCode());
        }

        $limited = $this->send();
        $this->assertError($limited, 429, 'rate_limited');
        $this->assertSame('60', $limited->getHeaderLine('Retry-After'));
        $this->assertSame(ClientsApi::RATE_LIMIT_REQUESTS, $this->nonceCount());

        // Another client is not affected.
        $this->assertSame(204, $this->send($this->environment->enrollClient(8))->getStatusCode());

        $this->environment->clock->advance(ClientsApi::RATE_LIMIT_WINDOW_SECONDS);
        $this->assertSame(204, $this->send()->getStatusCode());
    }

    public function testRateLimitHoldsForAClientWhoseClockIsBehind(): void
    {
        // Without the minimum of 60 seconds, each of these nonces would expire
        // as soon as it was stored, and the limit would never be reached.
        for ($i = 0; $i < ClientsApi::RATE_LIMIT_REQUESTS; $i++) {
            $this->assertSame(204, $this->send(timestamp: $this->environment->clock->now() - 300)->getStatusCode());
            $this->environment->clock->advance(1);
        }

        $this->assertError($this->send(timestamp: $this->environment->clock->now() - 300), 429, 'rate_limited');
    }

    public function testExpiredNoncesAreDeletedOnTheNextAcceptedRequest(): void
    {
        $this->send();
        $this->send(timestamp: $this->environment->clock->now() + 100);
        $this->environment->clock->advance(301);

        $this->assertSame(204, $this->send()->getStatusCode());

        // The first nonce expired at now + 300; the second at now + 400.
        $this->assertSame(2, $this->nonceCount());
        $expiries = $this->environment->database->pdo()->query('SELECT expires_at FROM clients_nonces ORDER BY expires_at')->fetchAll(\PDO::FETCH_COLUMN);
        $now = $this->environment->clock->now();
        $this->assertSame([$now - 301 + 400, $now + 300], array_map('intval', $expiries));
    }

    public function testReEnrollingDeletesTheOldClientsNonces(): void
    {
        $this->send();

        $this->environment->enrollClient(7);

        $this->assertSame(0, $this->nonceCount());
        $this->assertError($this->send(), 401, 'unauthorized');
    }

    public function testBodyOver64KiB(): void
    {
        $body = $this->body($this->client, ['padding' => str_repeat('x', 65536)]);

        $this->assertError($this->send(body: $body), 413, 'payload_too_large');
        $this->assertSame(0, $this->nonceCount());
    }

    /**
     * @return array<string, array{array<string, mixed>, int, string}>
     */
    public static function badBodies(): array
    {
        return [
            'another client_id' => [['client_id' => str_repeat('0', 32)], 400, 'bad_request'],
            'unsupported protocol' => [['protocol_version' => 2], 400, 'unsupported_protocol'],
            'sent_at as a string' => [['sent_at' => '1790000000'], 400, 'bad_request'],
            'readings not a list' => [['readings' => ['disk' => 1]], 400, 'bad_request'],
            'bad client_version' => [['client_version' => 'dev'], 400, 'bad_request'],
        ];
    }

    /**
     * @dataProvider badBodies
     * @param array<string, mixed> $overrides
     */
    public function testRejectsBadBodies(array $overrides, int $status, string $code): void
    {
        $this->assertError($this->send(body: $this->body($this->client, $overrides)), $status, $code);
        $this->assertNull($this->lastHeartbeatAt());
    }

    public function testOptionalListsMayBeMissing(): void
    {
        $body = json_encode(['protocol_version' => 1, 'client_version' => '0.1.0', 'client_id' => $this->client->clientId, 'sent_at' => 1]);

        $this->assertSame(204, $this->send(body: $body)->getStatusCode());
    }

    public function testWrongMethod(): void
    {
        $response = $this->environment->app()->handle((new ServerRequestFactory())->createServerRequest('GET', self::PATH));

        $this->assertError($response, 405, 'method_not_allowed');
    }

    public function testASecretTheKeyCannotDecryptIsAServerError(): void
    {
        $this->environment->database->pdo()->prepare('UPDATE clients_clients SET secret_ciphertext = ? WHERE client_id = ?')
            ->execute([random_bytes(72), $this->client->clientId]);

        $this->assertError($this->send(), 500, 'server_error');
        $this->assertSame(0, $this->nonceCount());
    }
}
