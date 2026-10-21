<?php

declare(strict_types=1);

namespace Maguari\Client\Tests;

use Maguari\Client\CertificateExpiry;
use Maguari\Client\ClientFailure;
use Maguari\Client\Credentials;
use Maguari\Client\DiskUsage;
use Maguari\Client\CheckSettings;
use Maguari\Client\CheckSettingsFile;
use Maguari\Client\HeartbeatSender;
use Maguari\Client\LocalChecks;
use Maguari\Client\Version;
use Maguari\Client\Tests\Support\FakeClock;
use Maguari\Client\Tests\Support\FakeFilesystemStats;
use Maguari\Client\Tests\Support\FakeSystemctl;
use Maguari\Client\Tests\Support\FakeTransport;
use Maguari\Client\Tests\Support\TemporaryDirectory;
use Maguari\Shared\Signature;
use PHPUnit\Framework\TestCase;

final class HeartbeatSenderTest extends TestCase
{
    private FakeTransport $transport;
    private FakeClock $clock;
    private Credentials $credentials;
    private HeartbeatSender $sender;
    private TemporaryDirectory $directory;
    private FakeSystemctl $systemctl;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
        $certificates = $this->directory->path . '/certificates.json';
        file_put_contents($certificates, json_encode(['scanned_at' => 1, 'certificates' => [
            ['name' => 'example.com', 'domains' => ['example.com'], 'expires_at' => 1_797_000_000],
        ]]));
        $this->transport = new FakeTransport();
        $this->clock = new FakeClock();
        $this->systemctl = new FakeSystemctl($this->clock);
        $this->credentials = new Credentials('https://maguari.example.com', str_repeat('c', 32), random_bytes(32));
        $stats = new FakeFilesystemStats(['/' => [10_000, 4_000]]);
        $this->sender = new HeartbeatSender($this->transport, $this->clock, new DiskUsage($stats, __DIR__ . '/fixtures/mounts-gce'), new CertificateExpiry($certificates), new CheckSettingsFile($this->directory->path), new LocalChecks($this->systemctl, $this->clock));
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
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
            'client_version' => Version::current(),
            'client_id' => str_repeat('c', 32),
            'sent_at' => 1_790_000_000,
            'readings' => [
                ['metric' => 'disk_used_bytes:/', 'value' => 6_000],
                ['metric' => 'disk_total_bytes:/', 'value' => 10_000],
                ['metric' => 'certificate_expires_at:example.com', 'value' => 1_797_000_000],
            ],
            'check_settings_version' => null,
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

    public function testSendsTheSettingsVersionAndTheResultsOfItsChecks(): void
    {
        (new CheckSettingsFile($this->directory->path))->save(CheckSettings::fromArray(['version' => '0123456789abcdef', 'checks' => [['type' => 'service', 'unit' => 'nginx.service']]]) ?? throw new \LogicException());
        $this->systemctl->unit('nginx.service', 'active');
        $this->transport->queue(204);

        $this->assertNull($this->sender->send($this->credentials));

        $body = json_decode($this->transport->requests[0]['body'], true);
        $this->assertSame('0123456789abcdef', $body['check_settings_version']);
        $this->assertSame([['type' => 'service', 'subject' => 'nginx.service', 'outcome' => 'pass', 'state' => 'active']], $body['checks']);
    }

    public function testStoresNewSettingsAndUsesThemFromTheNextHeartbeat(): void
    {
        $this->transport->queue(200, ['check_settings' => ['version' => 'fedcba9876543210', 'checks' => [
            ['type' => 'service', 'unit' => 'nginx.service'],
            ['type' => 'local_http', 'url' => 'http://127.0.0.1/'],
        ]]]);
        $this->transport->queue(204);

        $notice = $this->sender->send($this->credentials);
        $this->sender->send($this->credentials);

        $this->assertSame('Received check settings fedcba9876543210: 1 check. Skipped 1 this client cannot run.', $notice);
        $this->assertSame(0600, fileperms($this->directory->path . '/check-settings.json') & 0777);
        $second = json_decode($this->transport->requests[1]['body'], true);
        $this->assertSame('fedcba9876543210', $second['check_settings_version']);
        $this->assertSame([['type' => 'service', 'subject' => 'nginx.service', 'outcome' => 'fail', 'reason' => 'not_found']], $second['checks']);
    }

    public function testInvalidSettingsAreIgnoredAndTheOldOnesKept(): void
    {
        (new CheckSettingsFile($this->directory->path))->save(CheckSettings::fromArray(['version' => '0123456789abcdef', 'checks' => []]) ?? throw new \LogicException());
        $this->transport->queue(200, ['check_settings' => ['version' => 'not a version', 'checks' => []]]);

        $this->assertSame('Ignored check settings that were not valid; the server sends them again with the next heartbeat.', $this->sender->send($this->credentials));
        $this->assertSame('0123456789abcdef', (new CheckSettingsFile($this->directory->path))->load()?->version);
    }

    public function testUnknownFieldsInA200AreLeftForNewerClients(): void
    {
        $this->transport->queue(200, ['commands' => []]);

        $this->assertNull($this->sender->send($this->credentials));
    }
}
