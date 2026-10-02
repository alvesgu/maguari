<?php

declare(strict_types=1);

namespace Maguari\Client\Tests;

use Maguari\Client\CertificateExpiry;
use Maguari\Client\Cli;
use Maguari\Client\CredentialsFile;
use Maguari\Client\DiskUsage;
use Maguari\Client\Tests\Support\FakeClock;
use Maguari\Client\Tests\Support\FakeFilesystemStats;
use Maguari\Client\Tests\Support\FakeTransport;
use Maguari\Client\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    private const TOKEN = 'tttttttttttttttttttttttttttttttttttttttttt0';

    private TemporaryDirectory $directory;
    private FakeTransport $transport;
    private CredentialsFile $credentialsFile;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
        $this->transport = new FakeTransport();
        $this->credentialsFile = new CredentialsFile($this->directory->path . '/state');
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    /**
     * @param string[] $args
     * @return array{int, string, string} exit code, stdout and stderr
     */
    private function runCli(array $args, int $userId = 1000): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        $status = (new Cli($this->transport, new FakeClock(), new DiskUsage(new FakeFilesystemStats(), __DIR__ . '/fixtures/mounts-gce'), new CertificateExpiry(__DIR__ . '/fixtures/missing'), $this->credentialsFile, $userId, $stdout, $stderr))
            ->run(array_merge(['maguari-client'], $args));
        rewind($stdout);
        rewind($stderr);

        return [$status, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }

    private function queueEnrollment(string $clientId): void
    {
        $this->transport->queue(200, ['client_id' => $clientId, 'secret' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')]);
    }

    public function testEnrollWritesThePrivateCredentialsFile(): void
    {
        $this->queueEnrollment(str_repeat('e', 32));

        [$status, $stdout, $stderr] = $this->runCli(['enroll', '--server=https://maguari.example.com', '--token=' . self::TOKEN]);

        $this->assertSame(0, $status, $stderr);
        $this->assertStringContainsString('Enrolled with https://maguari.example.com.', $stdout);
        $this->assertSame(0600, fileperms($this->credentialsFile->path()) & 0777);
        $this->assertSame(str_repeat('e', 32), $this->credentialsFile->load()->clientId);
        $this->assertStringNotContainsString(self::TOKEN, $stdout . $stderr);
    }

    public function testAFailedEnrollmentKeepsTheEarlierCredentials(): void
    {
        $this->queueEnrollment(str_repeat('e', 32));
        $this->runCli(['enroll', '--server=https://maguari.example.com', '--token=' . self::TOKEN]);
        $this->transport->queue(401, ['error' => 'invalid_token']);

        [$status, , $stderr] = $this->runCli(['enroll', '--server=https://maguari.example.com', '--token=' . self::TOKEN]);

        $this->assertSame(1, $status);
        $this->assertSame("The enrollment token is unknown, already used or expired. Press Enroll on the server for a new one.\n", $stderr);
        $this->assertSame(str_repeat('e', 32), $this->credentialsFile->load()->clientId);
    }

    public function testEnrollRefusesPlainHttpOutsideLocalhost(): void
    {
        [$status, , $stderr] = $this->runCli(['enroll', '--server=http://maguari.example.com', '--token=' . self::TOKEN]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('http:// is accepted only for localhost', $stderr);
        $this->assertSame([], $this->transport->requests);
        $this->assertFileDoesNotExist($this->credentialsFile->path());
    }

    public function testHeartbeat(): void
    {
        $this->queueEnrollment(str_repeat('e', 32));
        $this->runCli(['enroll', '--server=https://maguari.example.com', '--token=' . self::TOKEN]);
        $this->transport->queue(204);

        [$status, $stdout] = $this->runCli(['heartbeat']);

        $this->assertSame(0, $status);
        $this->assertSame("Heartbeat accepted by https://maguari.example.com.\n", $stdout);
    }

    public function testHeartbeatBeforeEnrolling(): void
    {
        [$status, , $stderr] = $this->runCli(['heartbeat']);

        $this->assertSame(1, $status);
        $this->assertStringStartsWith('This client is not enrolled', $stderr);
        $this->assertSame(1, substr_count($stderr, "\n"));
    }

    /**
     * @return array<string, array{string[]}>
     */
    public static function commands(): array
    {
        return [
            'enroll' => [['enroll', '--server=https://maguari.example.com', '--token=' . self::TOKEN]],
            'heartbeat' => [['heartbeat']],
            'run' => [['run']],
        ];
    }

    /**
     * @dataProvider commands
     * @param string[] $args
     */
    public function testRefusesRoot(array $args): void
    {
        [$status, $stdout, $stderr] = $this->runCli($args, 0);

        $this->assertSame(1, $status);
        $this->assertSame('', $stdout);
        $this->assertStringStartsWith('Do not run maguari-client as root.', $stderr);
        $this->assertSame([], $this->transport->requests);
        $this->assertDirectoryDoesNotExist($this->directory->path . '/state');
    }

    public function testUsage(): void
    {
        foreach ([[], ['unknown'], ['enroll', '--server=https://maguari.example.com']] as $args) {
            [$status, , $stderr] = $this->runCli($args);

            $this->assertSame(1, $status);
            $this->assertStringStartsWith('Usage:', $stderr);
        }
    }
}
