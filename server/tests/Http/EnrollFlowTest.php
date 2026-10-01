<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Http;

use Maguari\Server\Clients\EnrollmentState;
use Maguari\Server\Clients\EnrollmentTokens;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

final class EnrollFlowTest extends TestCase
{
    private const INSTANCE_ID = 7;

    private TestEnvironment $environment;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
    }

    protected function tearDown(): void
    {
        $this->environment->cleanUp();
    }

    private function post(string $body): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/client/enroll')
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream($body));

        return $this->environment->app()->handle($request);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function enroll(string $token, array $overrides = []): ResponseInterface
    {
        return $this->post(json_encode($overrides + ['protocol_version' => 1, 'client_version' => '0.1.0', 'token' => $token]));
    }

    private function issueToken(): string
    {
        return $this->environment->clients->issueEnrollmentToken(self::INSTANCE_ID)->token;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function storedClients(): array
    {
        return $this->environment->database->pdo()->query('SELECT * FROM clients_clients')->fetchAll();
    }

    private function assertError(ResponseInterface $response, int $status, string $code): void
    {
        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame(json_encode(['error' => $code]), (string) $response->getBody());
    }

    public function testExchangesTheTokenForCredentials(): void
    {
        $response = $this->enroll($this->issueToken());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame(['client_id', 'secret'], array_keys($data));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $data['client_id']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $data['secret']);
        $secret = base64_decode(strtr($data['secret'], '-_', '+/'), true);
        $this->assertSame(32, strlen($secret));

        [$row] = $this->storedClients();
        $this->assertSame($data['client_id'], $row['client_id']);
        $this->assertSame(self::INSTANCE_ID, (int) $row['instance_id']);
        $this->assertSame($this->environment->clock->now(), (int) $row['enrolled_at']);
        $this->assertSame('0.1.0', $row['client_version']);
        $this->assertSame(1, (int) $row['protocol_version']);
        $this->assertNull($row['last_heartbeat_at']);
        $this->assertSame($secret, $this->environment->secretBox->decrypt($row['secret_ciphertext']));
        $this->assertSame([self::INSTANCE_ID => EnrollmentState::Enrolled], $this->environment->clients->enrollmentStates([self::INSTANCE_ID]));
    }

    public function testThePlainSecretIsNeverInTheDatabaseFile(): void
    {
        $data = json_decode((string) $this->enroll($this->issueToken())->getBody(), true);
        $secret = base64_decode(strtr($data['secret'], '-_', '+/'), true);
        $this->environment->database->pdo()->exec('PRAGMA wal_checkpoint(TRUNCATE)');

        $this->assertStringNotContainsString($secret, (string) file_get_contents($this->environment->database->path()));
    }

    public function testATokenWorksOnce(): void
    {
        $token = $this->issueToken();
        $this->assertSame(200, $this->enroll($token)->getStatusCode());

        $this->assertError($this->enroll($token), 401, 'invalid_token');
        $this->assertCount(1, $this->storedClients());
    }

    public function testExpiredToken(): void
    {
        $token = $this->issueToken();
        $this->environment->clock->advance(EnrollmentTokens::LIFETIME_SECONDS);

        $this->assertError($this->enroll($token), 401, 'invalid_token');
        $this->assertSame([], $this->storedClients());
    }

    public function testUnknownAndMalformedTokens(): void
    {
        $this->issueToken();

        $this->assertError($this->enroll(str_repeat('A', 43)), 401, 'invalid_token');
        $this->assertError($this->enroll('short'), 401, 'invalid_token');
        $this->assertSame([], $this->storedClients());
    }

    public function testEnrollingAgainReplacesTheInstancesClient(): void
    {
        $first = json_decode((string) $this->enroll($this->issueToken())->getBody(), true);

        $second = json_decode((string) $this->enroll($this->issueToken())->getBody(), true);

        $this->assertNotSame($first['client_id'], $second['client_id']);
        $this->assertSame([$second['client_id']], array_column($this->storedClients(), 'client_id'));
    }

    public function testUnsupportedProtocolVersion(): void
    {
        $token = $this->issueToken();

        $this->assertError($this->enroll($token, ['protocol_version' => 2]), 400, 'unsupported_protocol');
        $this->assertError($this->enroll($token, ['protocol_version' => 0]), 400, 'unsupported_protocol');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedBodies(): array
    {
        $valid = ['protocol_version' => 1, 'client_version' => '0.1.0'];

        return [
            'empty' => [''],
            'not JSON' => ['protocol_version=1'],
            'a list' => ['[1, "0.1.0"]'],
            'empty object' => ['{}'],
            'protocol version as a string' => [json_encode(['protocol_version' => '1'] + $valid + ['token' => 'x'])],
            'missing client version' => [json_encode(['protocol_version' => 1, 'token' => 'x'])],
            'client version not a version' => [json_encode(['client_version' => 'latest'] + $valid + ['token' => 'x'])],
            'client version with a newline' => [json_encode(['client_version' => "0.1.0\n"] + $valid + ['token' => 'x'])],
            'missing token' => [json_encode($valid)],
            'token not a string' => [json_encode($valid + ['token' => 42])],
            'too deep' => ['{"a": {"b": {"c": {"d": 1}}}}'],
        ];
    }

    /**
     * @dataProvider malformedBodies
     */
    public function testMalformedBody(string $body): void
    {
        $this->assertError($this->post($body), 400, 'bad_request');
    }

    public function testARejectedRequestDoesNotUseTheToken(): void
    {
        $token = $this->issueToken();
        $this->assertError($this->enroll($token, ['client_version' => 'latest']), 400, 'bad_request');

        $this->assertSame(200, $this->enroll($token)->getStatusCode());
    }

    public function testBodyOver64KiB(): void
    {
        $token = $this->issueToken();
        $body = json_encode(['protocol_version' => 1, 'client_version' => '0.1.0', 'token' => $token, 'padding' => str_repeat('x', 65536)]);

        $this->assertError($this->post($body), 413, 'payload_too_large');
        $this->assertSame(200, $this->enroll($token)->getStatusCode());
    }

    public function testUnknownFieldsAreIgnored(): void
    {
        $this->assertSame(200, $this->enroll($this->issueToken(), ['hostname' => 'web-1'])->getStatusCode());
    }
}
