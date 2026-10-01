<?php

declare(strict_types=1);

namespace Maguari\Client\Tests;

use Maguari\Client\ClientFailure;
use Maguari\Client\Credentials;
use Maguari\Client\HeartbeatSender;
use Maguari\Client\Tests\Support\FakeClock;
use Maguari\Client\Tests\Support\FakeTransport;
use Maguari\Shared\Signature;
use PHPUnit\Framework\TestCase;

final class HeartbeatSenderTest extends TestCase
{
    private FakeTransport $transport;
    private FakeClock $clock;
    private Credentials $credentials;
    private HeartbeatSender $sender;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->clock = new FakeClock();
        $this->credentials = new Credentials('https://maguari.example.com', str_repeat('c', 32), random_bytes(32));
        $this->sender = new HeartbeatSender($this->transport, $this->clock);
    }

    public function testSendsASignedHeartbeat(): void
    {
        $this->transport->queue(204);

        $this->sender->send($this->credentials);

        [$request] = $this->transport->requests;
        $this->assertSame('https://maguari.example.com/api/client/heartbeat', $request['url']);
        $headers = $request['headers'];
        $this->assertSame(str_repeat('c', 32), $headers['X-Maguari-Client']);
        $this->assertSame('1790000000', $headers['X-Maguari-Timestamp']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $headers['X-Maguari-Nonce']);
        $this->assertTrue(Signature::verify(
            $this->credentials->secret,
            $headers['X-Maguari-Signature'],
            'POST',
            '/api/client/heartbeat',
            $headers['X-Maguari-Timestamp'],
            $headers['X-Maguari-Nonce'],
            $request['body'],
        ));
        $this->assertSame([
            'protocol_version' => 1,
            'client_version' => '0.1.0',
            'client_id' => str_repeat('c', 32),
            'sent_at' => 1_790_000_000,
            'readings' => [],
            'checks' => [],
            'command_results' => [],
        ], json_decode($request['body'], true));
    }

    public function testEveryHeartbeatHasANewNonce(): void
    {
        $this->transport->queue(204);
        $this->transport->queue(204);

        $this->sender->send($this->credentials);
        $this->sender->send($this->credentials);

        $this->assertNotSame($this->transport->requests[0]['headers']['X-Maguari-Nonce'], $this->transport->requests[1]['headers']['X-Maguari-Nonce']);
    }

    public function testAnEmpty200IsAccepted(): void
    {
        $this->transport->queue(200);

        $this->sender->send($this->credentials);

        $this->assertCount(1, $this->transport->requests);
    }

    /**
     * @return array<string, array{int, array<string, mixed>|null, string}>
     */
    public static function failures(): array
    {
        return [
            'clock behind' => [401, ['error' => 'clock_skew', 'server_time' => 1_790_000_400], 'This instance\'s clock is 400 seconds behind the server\'s; at most 300 are allowed.'],
            'clock ahead' => [401, ['error' => 'clock_skew', 'server_time' => 1_789_999_000], 'This instance\'s clock is 1000 seconds ahead of the server\'s'],
            'unauthorized' => [401, ['error' => 'unauthorized'], 'Enroll the client again.'],
            'rate limited' => [429, ['error' => 'rate_limited'], 'too many requests'],
            'unavailable' => [503, ['error' => 'unavailable'], 'The server is not ready'],
            'server error' => [500, ['error' => 'server_error'], 'Its error log has the details.'],
            'not Maguari' => [502, null, 'HTTP 502 without a Maguari error code'],
            'unknown code' => [418, ['error' => 'teapot'], 'HTTP 418 without a Maguari error code'],
            '200 with a body' => [200, ['commands' => []], 'HTTP 200 without a Maguari error code'],
        ];
    }

    /**
     * @dataProvider failures
     * @param array<string, mixed>|null $json
     */
    public function testFailuresBecomeOneSentence(int $status, ?array $json, string $expected): void
    {
        $this->transport->queue($status, $json);

        try {
            $this->sender->send($this->credentials);
            $this->fail('Expected ClientFailure.');
        } catch (ClientFailure $failure) {
            $this->assertStringContainsString($expected, $failure->getMessage());
            $this->assertStringNotContainsString("\n", $failure->getMessage());
        }
    }
}
