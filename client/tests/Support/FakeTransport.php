<?php

declare(strict_types=1);

namespace Maguari\Client\Tests\Support;

use Maguari\Client\ClientFailure;
use Maguari\Client\Transport;
use Maguari\Client\TransportResponse;

/**
 * Returns queued responses in order and records every request. No network.
 */
final class FakeTransport implements Transport
{
    /** @var list<TransportResponse|ClientFailure> */
    private array $queue = [];

    /** @var list<array{url: string, headers: array<string, string>, body: string}> */
    public array $requests = [];

    /**
     * @param array<string, mixed>|null $json
     */
    public function queue(int $status, ?array $json = null): void
    {
        $this->queue[] = new TransportResponse($status, $json === null ? '' : json_encode($json, JSON_THROW_ON_ERROR));
    }

    public function queueFailure(string $message): void
    {
        $this->queue[] = new ClientFailure($message);
    }

    public function post(string $url, array $headers, string $body): TransportResponse
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
        $next = array_shift($this->queue) ?? throw new \RuntimeException('Unexpected request to ' . $url);

        if ($next instanceof ClientFailure) {
            throw $next;
        }

        return $next;
    }
}
