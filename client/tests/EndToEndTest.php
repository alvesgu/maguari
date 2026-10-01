<?php

declare(strict_types=1);

namespace Maguari\Client\Tests;

use Maguari\Client\Cli;
use Maguari\Client\CredentialsFile;
use Maguari\Client\Tests\Support\FakeClock;
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
     * @param string[] $args
     * @return array{int, string}
     */
    private function client(array $args): array
    {
        $stderr = fopen('php://memory', 'w+');
        // The client's clock agrees with the server's.
        $clock = new FakeClock($this->server->clock->now());
        $cli = new Cli($this->transport, $clock, new CredentialsFile($this->directory->path), 1000, fopen('php://memory', 'w+'), $stderr);
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

        // The token worked once.
        [$status, $stderr] = $this->client(['enroll', '--server=http://localhost:8080', '--token=' . $token]);
        $this->assertSame(1, $status);
        $this->assertStringContainsString('already used or expired', $stderr);
    }

    public function testClockSkewIsExplainedInSeconds(): void
    {
        $token = $this->server->clients->issueEnrollmentToken(7)->token;
        $this->client(['enroll', '--server=http://localhost:8080', '--token=' . $token]);
        $this->server->clock->advance(400);
        $stderr = fopen('php://memory', 'w+');
        $cli = new Cli($this->transport, new FakeClock($this->server->clock->now() - 400), new CredentialsFile($this->directory->path), 1000, fopen('php://memory', 'w+'), $stderr);

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
