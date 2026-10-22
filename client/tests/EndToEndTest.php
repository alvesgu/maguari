<?php

declare(strict_types=1);

namespace Maguari\Client\Tests;

use Maguari\Client\CertificateExpiry;
use Maguari\Client\Cli;
use Maguari\Client\CredentialsFile;
use Maguari\Client\DatabaseConnection;
use Maguari\Client\DatabasesFile;
use Maguari\Client\DiskUsage;
use Maguari\Client\Tests\Support\FakeClock;
use Maguari\Client\Tests\Support\FakeDatabaseConnector;
use Maguari\Client\Tests\Support\FakeFilesystemStats;
use Maguari\Client\Tests\Support\FakeLocalHttp;
use Maguari\Client\Tests\Support\FakeSystemctl;
use Maguari\Client\Tests\Support\TemporaryDirectory;
use Maguari\Client\Transport;
use Maguari\Client\TransportResponse;
use Maguari\Server\Monitoring\Domain\HeartbeatState;
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
    private FakeSystemctl $systemctl;
    private FakeLocalHttp $localHttp;
    private FakeDatabaseConnector $databases;
    /** The last client command's standard output. */
    private string $stdout = '';
    /** @var array<string, array{int, int}> total and free bytes, by mount point */
    private array $sizes = ['/' => [10_000_000, 4_000_000], '/boot' => [1_000_000, 900_000]];

    protected function setUp(): void
    {
        $this->server = new TestEnvironment();
        $this->directory = new TemporaryDirectory();
        $this->systemctl = new FakeSystemctl();
        $this->localHttp = new FakeLocalHttp();
        $this->databases = new FakeDatabaseConnector();
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
        $stdout = fopen('php://memory', 'w+');
        $cli = new Cli($this->transport, $clock, $this->diskUsage(), $this->certificates(), new CredentialsFile($this->directory->path), $this->systemctl, 1000, $stdout, $stderr, $this->localHttp, $this->databases);
        $status = $cli->run(array_merge(['maguari-client'], $args));
        rewind($stderr);
        rewind($stdout);
        $this->stdout = (string) stream_get_contents($stdout);

        return [$status, (string) stream_get_contents($stderr)];
    }

    public function testEnrollAndSendHeartbeats(): void
    {
        $token = $this->server->clients->issueEnrollmentToken(7)->token;

        [$status, $stderr] = $this->client(['enroll', '--server=http://localhost:8080', '--token=' . $token]);
        $this->assertSame(0, $status, $stderr);
        $this->assertSame(HeartbeatState::NoHeartbeatYet, $this->server->monitoring->heartbeatStatuses([7])[7]->state);

        [$status, $stderr] = $this->client(['heartbeat']);
        $this->assertSame(0, $status, $stderr);
        $this->assertSame(HeartbeatState::OnTime, $this->server->monitoring->heartbeatStatuses([7])[7]->state);

        // A second heartbeat with a new nonce is accepted too.
        $this->server->clock->advance(60);
        [$status, $stderr] = $this->client(['heartbeat']);
        $this->assertSame(0, $status, $stderr);
        $this->assertSame(0, $this->server->monitoring->heartbeatStatuses([7])[7]->ageSeconds);

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
        $cli = new Cli($this->transport, new FakeClock($this->server->clock->now() - 400), $this->diskUsage(), $this->certificates(), new CredentialsFile($this->directory->path), $this->systemctl, 1000, fopen('php://memory', 'w+'), $stderr);

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

    /**
     * @return array<string, array{string, string}> outcome and sentence by unit
     */
    private function serviceStates(): array
    {
        $states = [];

        foreach ($this->server->monitoring->clientCheckStatuses([7])[7] ?? [] as $status) {
            $states[$status->check->target] = [$status->outcome()->value, $status->detail()];
        }

        return $states;
    }

    public function testServiceChecksTravelOnlyWhenTheyChangeAndFailAfterTwoInARow(): void
    {
        $this->client(['enroll', '--server=http://localhost:8080', '--token=' . $this->server->clients->issueEnrollmentToken(7)->token]);
        $monitoring = $this->server->monitoring;
        $monitoring->addServiceCheck(7, 'nginx');
        $this->systemctl->unit('nginx.service', 'active');

        // The first heartbeat says it has no settings and gets them.
        [$status, $stderr] = $this->client(['heartbeat']);
        $this->assertSame(0, $status, $stderr);
        $version = $monitoring->clientCheckSettings(7)->version;
        $this->assertStringContainsString("Received check settings {$version}: 1 check.", $this->stdout);
        $this->assertSame([], $this->systemctl->shown, 'Nothing was checked before the settings arrived.');

        // The next one reports the result, and the answer is empty again.
        $this->server->clock->advance(60);
        $this->client(['heartbeat']);
        $this->assertSame("Heartbeat accepted by http://localhost:8080.\n", $this->stdout);
        $this->assertSame(['nginx.service' => ['pass', 'nginx.service is active.']], $this->serviceStates());
        $reported = $this->server->clients->reportedCheckSettings([7])[7];
        $this->assertSame([true, $version], [$reported->supported, $reported->version]);

        // A stopped service fails on the second failed result in a row.
        $this->systemctl->unit('nginx.service', 'failed');
        $this->server->clock->advance(60);
        $this->client(['heartbeat']);
        $this->assertSame('pass', $this->serviceStates()['nginx.service'][0], 'One failure does not count yet.');
        $this->server->clock->advance(60);
        $this->client(['heartbeat']);
        $this->assertSame(['nginx.service' => ['fail', 'nginx.service is failed, not active.']], $this->serviceStates());
        $this->assertSame(['monitoring.check_passed', 'monitoring.check_failed'], $this->server->database->pdo()->query('SELECT type FROM kernel_events ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));

        // A new check reaches the client with the next heartbeat.
        $monitoring->addServiceCheck(7, 'php8.3-fpm.service');
        $this->server->clock->advance(60);
        $this->client(['heartbeat']);
        $this->assertStringContainsString(': 2 checks.', $this->stdout);
    }

    public function testLocalHttpChecksTravelAndAreJudgedEndToEnd(): void
    {
        $this->client(['enroll', '--server=http://localhost:8080', '--token=' . $this->server->clients->issueEnrollmentToken(7)->token]);
        $monitoring = $this->server->monitoring;
        $monitoring->addLocalHttpCheck(7, 'https://127.0.0.1/health', 'geonode.example.com');
        $this->localHttp->answer('https://127.0.0.1/health', 'geonode.example.com', 'refused');

        $this->client(['heartbeat']);
        $this->assertStringContainsString(': 1 check.', $this->stdout);

        foreach ([1, 2] as $heartbeat) {
            $this->server->clock->advance(60);
            [$status, $stderr] = $this->client(['heartbeat']);
            $this->assertSame(0, $status, $stderr);
        }

        $state = $monitoring->clientCheckStatuses([7])[7][0];
        $this->assertSame(['fail', 'Nothing answered on port 443 of the instance: the connection was refused.'], [$state->outcome()->value, $state->detail()]);
        $this->assertSame([['https://127.0.0.1/health', 'geonode.example.com'], ['https://127.0.0.1/health', 'geonode.example.com']], $this->localHttp->requests);
    }

    public function testDatabaseChecksNameADatabaseWhoseLoginStaysOnTheInstance(): void
    {
        $this->client(['enroll', '--server=http://localhost:8080', '--token=' . $this->server->clients->issueEnrollmentToken(7)->token]);
        (new DatabasesFile($this->directory->path))->save(['geonode' => new DatabaseConnection('postgresql', '127.0.0.1', 5432, 'geonode', 'maguari_check', 'secret-password')]);
        $monitoring = $this->server->monitoring;
        $monitoring->addDatabaseCheck(7, 'geonode');
        $monitoring->addDatabaseCheck(7, 'reports');
        $this->databases->answer('geonode', 'unreachable');

        foreach ([1, 2, 3] as $heartbeat) {
            $this->server->clock->advance(60);
            [$status, $stderr] = $this->client(['heartbeat']);
            $this->assertSame(0, $status, $stderr);
        }

        $states = [];

        foreach ($monitoring->clientCheckStatuses([7])[7] as $status) {
            $states[$status->check->target] = [$status->outcome()->value, $status->detail()];
        }

        $this->assertSame([
            'geonode' => ['fail', 'Could not connect to the database server of geonode, or it is not accepting connections.'],
            'reports' => ['not_checked', 'No database named reports is set up on the instance. Run maguari-client set-database reports there.'],
        ], $states, 'reports is not set up on the instance: not checked, shown with its sentence, never stored as an outcome.');
        // Every row of every table on the server.
        $pdo = $this->server->database->pdo();
        $dump = '';

        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $dump .= json_encode($pdo->query("SELECT * FROM \"{$table}\"")->fetchAll(), JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $this->assertStringNotContainsString('secret-password', $dump);
        $this->assertStringNotContainsString('maguari_check', $dump, 'Not even the user reaches the server.');
    }
}
