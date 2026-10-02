<?php

declare(strict_types=1);

namespace Maguari\Client\Tests;

use Maguari\Client\CertificateExpiry;
use Maguari\Client\ClientFailure;
use Maguari\Client\Clock;
use Maguari\Client\Credentials;
use Maguari\Client\DiskUsage;
use Maguari\Client\HeartbeatSender;
use Maguari\Client\Runner;
use Maguari\Client\Tests\Support\FakeClock;
use Maguari\Client\Tests\Support\FakeFilesystemStats;
use Maguari\Client\Tests\Support\FakeTransport;
use Maguari\Client\Transport;
use Maguari\Client\TransportResponse;
use PHPUnit\Framework\TestCase;

final class RunnerTest extends TestCase
{
    /** @var list<string> */
    private array $log = [];

    private function runner(Transport $transport, Clock $clock): Runner
    {
        return new Runner(new HeartbeatSender($transport, $clock, new DiskUsage(new FakeFilesystemStats(), __DIR__ . '/fixtures/mounts-gce'), new CertificateExpiry(__DIR__ . '/fixtures/missing')), $clock, function (string $message): void {
            $this->log[] = $message;
        });
    }

    private static function credentials(): Credentials
    {
        return new Credentials('https://maguari.example.com', str_repeat('d', 32), random_bytes(32));
    }

    public function testSendsEveryMinuteOnAFixedSchedule(): void
    {
        $clock = new FakeClock();
        $start = $clock->now();
        $sentAt = [];
        $transport = new class ($clock, $sentAt) implements Transport {
            /** @param list<int> $sentAt */
            public function __construct(private readonly FakeClock $clock, private array &$sentAt)
            {
            }

            public function post(string $url, array $headers, string $body): TransportResponse
            {
                $this->sentAt[] = $this->clock->now();
                // Each heartbeat takes 3 seconds; the schedule must not drift.
                $this->clock->now += 3;

                return new TransportResponse(204, '');
            }
        };

        $this->runner($transport, $clock)->run(self::credentials(), 4);

        $this->assertSame([$start, $start + 60, $start + 120, $start + 180], $sentAt);
        $this->assertSame([57, 57, 57], $clock->sleeps);
        $this->assertSame(['Sending a heartbeat to https://maguari.example.com every 60 seconds.'], $this->log);
    }

    public function testKeepsGoingAfterFailuresAndSaysWhenTheyStop(): void
    {
        $clock = new FakeClock();
        $transport = new FakeTransport();
        $transport->queueFailure('No response from maguari.example.com: Connection refused.');
        $transport->queue(401, ['error' => 'clock_skew', 'server_time' => $clock->now() + 60 + 400]);
        $transport->queue(204);

        $this->runner($transport, $clock)->run(self::credentials(), 3);

        $this->assertCount(3, $transport->requests);
        $this->assertSame([60, 60], $clock->sleeps);
        $this->assertSame([
            'Sending a heartbeat to https://maguari.example.com every 60 seconds.',
            'Heartbeat failed: No response from maguari.example.com: Connection refused.',
            'Heartbeat failed: This instance\'s clock is 400 seconds behind the server\'s; at most 300 are allowed. Check time synchronization (timedatectl).',
            'Heartbeats are getting through again.',
        ], $this->log);
    }

    public function testSkipsSlotsThatPassedInsteadOfSendingABurst(): void
    {
        $clock = new FakeClock();
        $start = $clock->now();
        $sentAt = [];
        $transport = new class ($clock, $sentAt) implements Transport {
            /** @param list<int> $sentAt */
            public function __construct(private readonly FakeClock $clock, private array &$sentAt)
            {
            }

            public function post(string $url, array $headers, string $body): TransportResponse
            {
                $this->sentAt[] = $this->clock->now();

                if (count($this->sentAt) === 1) {
                    // A first heartbeat that hangs for 130 seconds.
                    $this->clock->now += 130;

                    throw new ClientFailure('No complete response from maguari.example.com: timed out.');
                }

                return new TransportResponse(204, '');
            }
        };

        $this->runner($transport, $clock)->run(self::credentials(), 2);

        $this->assertSame([$start, $start + 180], $sentAt);
    }
}
