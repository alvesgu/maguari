<?php

declare(strict_types=1);

namespace Maguari\Client\Tests;

use Maguari\Client\CertificateExpiry;
use Maguari\Client\Cli;
use Maguari\Client\CredentialsFile;
use Maguari\Client\DiskUsage;
use Maguari\Client\Tests\Support\FakeClock;
use Maguari\Client\Tests\Support\FakeFilesystemStats;
use Maguari\Client\Tests\Support\TemporaryDirectory;
use Maguari\Client\Transport;
use Maguari\Client\TransportResponse;
use Maguari\Server\Clients\HeartbeatState;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * The real client against the real server app: only the network is replaced,
 * by a transport that hands each request to the Slim app in this process.
 */
final class EndToEndTest extends TestCase
{
    private TestEnvironment $server;
    private TemporaryDirectory $directory;
    private Transport $transport;
    /** @var array<string, array{int, int}> total and free bytes, by mount point */
    private array $sizes = ['/' => [10_000_000, 4_000_000], '/boot' => [1_000_000, 900_000]];

    protected function setUp(): void
    {
        $this->server = new TestEnvironment();
        $this->directory = new TemporaryDirectory();
        $server = $this->server;
        $this->transport = new class ($server) implements Transport {
            public function __construct(private readonly TestEnvironment $server)
            {
            }

            public function post(string $url, array $headers, string $body): TransportResponse
            {
                $request = (new ServerRequestFactory())->createServerRequest('POST', $url)
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody((new StreamFactory())->createStream($body));

                foreach ($headers as $name => $value) {
                    $request = $request->withHeader($name, $value);
                }

                $response = $this->server->app()->handle($request);

                return new TransportResponse($response->getStatusCode(), (string) $response->getBody());
            }
        };
    }

    protected function tearDown(): void
    {
        $this->server->cleanUp();
        $this->directory->remove();
    }

    /**
     * Fixture mounts and fake sizes, because a test container's / is overlay
     * and would report nothing.
     */
    private function diskUsage(): DiskUsage
    {
        return new DiskUsage(new FakeFilesystemStats($this->sizes), __DIR__ . '/fixtures/mounts-gce');
    }

    /**
     * The scanner's file, as the root-owned scanner would write it.
     */
    private function certificates(): CertificateExpiry
    {
        $path = $this->directory->path . '/certificates.json';
        file_put_contents($path, json_encode(['scanned_at' => 1, 'certificates' => [
            ['name' => 'example.com', 'domains' => ['example.com', 'www.example.com'], 'expires_at' => 1_797_000_000],
        ]]));

        return new CertificateExpiry($path);
    }

    /**
     * @param string[] $args
     * @return array{int, string}
     */
    private function client(array $args): array
    {
        $stderr = fopen('php://memory', 'w+');
        // The client's clock agrees with the server's.
        $clock = new FakeClock($this->server->clock->now());
        $cli = new Cli($this->transport, $clock, $this->diskUsage(), $this->certificates(), new CredentialsFile($this->directory->path), 1000, fopen('php://memory', 'w+'), $stderr);
        $status = $cli->run(array_merge(['maguari-client'], $args));
        rewind($stderr);

        return [$status, (string) stream_get_contents($stderr)];
    }

    public function testEnrollAndSendHeartbeats(): void
    {
        $token = $this->server->clients->issueEnrollmentToken(7)->token;

        [$status, $stderr] = $this->client(['enroll', '--server=http://localhost:8080', '--token=' . $token]);
        $this->assertSame(0, $status, $stderr);
        $this->assertSame(HeartbeatState::NoHeartbeatYet, $this->server->clients->heartbeatStatuses([7])[7]->state);

        [$status, $stderr] = $this->client(['heartbeat']);
        $this->assertSame(0, $status, $stderr);
        $this->assertSame(HeartbeatState::OnTime, $this->server->clients->heartbeatStatuses([7])[7]->state);

        // A second heartbeat with a new nonce is accepted too.
        $this->server->clock->advance(60);
        [$status, $stderr] = $this->client(['heartbeat']);
        $this->assertSame(0, $status, $stderr);
        $this->assertSame(0, $this->server->clients->heartbeatStatuses([7])[7]->ageSeconds);

        // Both heartbeats' readings went into one run per metric.
        $now = $this->server->clock->now();
        $this->assertSame([
            ['instance_id' => 7, 'metric' => 'disk_used_bytes:/', 'value' => 6_000_000, 'start_at' => $now - 60, 'end_at' => $now],
            ['instance_id' => 7, 'metric' => 'disk_total_bytes:/', 'value' => 10_000_000, 'start_at' => $now - 60, 'end_at' => $now],
            ['instance_id' => 7, 'metric' => 'disk_used_bytes:/boot', 'value' => 100_000, 'start_at' => $now - 60, 'end_at' => $now],
            ['instance_id' => 7, 'metric' => 'disk_total_bytes:/boot', 'value' => 1_000_000, 'start_at' => $now - 60, 'end_at' => $now],
            ['instance_id' => 7, 'metric' => 'certificate_expires_at:example.com', 'value' => 1_797_000_000, 'start_at' => $now - 60, 'end_at' => $now],
        ], $this->runs());

        // Used space moving more than 0.1% of the total starts a new run.
        $this->sizes['/'] = [10_000_000, 3_989_999];
        $this->server->clock->advance(60);
        [$status, $stderr] = $this->client(['heartbeat']);
        $this->assertSame(0, $status, $stderr);
        $this->assertSame([6_000_000, 6_010_001], array_column(array_filter(
            $this->runs(),
            fn (array $run): bool => $run['metric'] === 'disk_used_bytes:/',
        ), 'value'));

        // The token worked once.
        [$status, $stderr] = $this->client(['enroll', '--server=http://localhost:8080', '--token=' . $token]);
        $this->assertSame(1, $status);
        $this->assertStringContainsString('already used or expired', $stderr);
    }

    /**
     * @return list<array{instance_id: int, metric: string, value: int, start_at: int, end_at: int}>
     */
    private function runs(): array
    {
        return $this->server->database->pdo()->query(
            'SELECT instance_id, metric, value, start_at, end_at FROM monitoring_metric_runs ORDER BY id',
        )->fetchAll();
    }

    public function testClockSkewIsExplainedInSeconds(): void
    {
        $token = $this->server->clients->issueEnrollmentToken(7)->token;
        $this->client(['enroll', '--server=http://localhost:8080', '--token=' . $token]);
        $this->server->clock->advance(400);
        $stderr = fopen('php://memory', 'w+');
        $cli = new Cli($this->transport, new FakeClock($this->server->clock->now() - 400), $this->diskUsage(), $this->certificates(), new CredentialsFile($this->directory->path), 1000, fopen('php://memory', 'w+'), $stderr);

        $this->assertSame(1, $cli->run(['maguari-client', 'heartbeat']));
        rewind($stderr);
        $this->assertStringStartsWith('This instance\'s clock is 400 seconds behind the server\'s', (string) stream_get_contents($stderr));
    }

    public function testReEnrollingReplacesTheCredentials(): void
    {
        $this->client(['enroll', '--server=http://localhost:8080', '--token=' . $this->server->clients->issueEnrollmentToken(7)->token]);
        $old = (new CredentialsFile($this->directory->path))->load();

        $this->client(['enroll', '--server=http://localhost:8080', '--token=' . $this->server->clients->issueEnrollmentToken(7)->token]);

        $this->assertNotSame($old->clientId, (new CredentialsFile($this->directory->path))->load()->clientId);
        [$status] = $this->client(['heartbeat']);
        $this->assertSame(0, $status);
    }
}
