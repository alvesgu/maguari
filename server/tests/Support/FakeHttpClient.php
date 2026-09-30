<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Support;

use Maguari\Server\Kernel\HttpClient\HttpClient;
use Maguari\Server\Kernel\HttpClient\HttpClientFailure;
use Maguari\Server\Kernel\HttpClient\HttpRequest;
use Maguari\Server\Kernel\HttpClient\HttpResponse;
use RuntimeException;

/**
 * Returns queued responses in order and records every request. No network.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var list<HttpResponse|HttpClientFailure> */
    private array $queue = [];

    /** @var list<HttpRequest> */
    public array $requests = [];

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function queueJson(int $status, array $data, array $headers = []): void
    {
        $this->queue[] = new HttpResponse($status, $headers + ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function queue(HttpResponse $response): void
    {
        $this->queue[] = $response;
    }

    public function queueFailure(string $message = 'No response from example.test: Connection refused'): void
    {
        $this->queue[] = new HttpClientFailure($message);
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);

        if ($next === null) {
            throw new RuntimeException(sprintf('Unexpected request: %s %s', $request->method, $request->url));
        }

        if ($next instanceof HttpClientFailure) {
            throw $next;
        }

        return $next;
    }

    public function lastRequest(): HttpRequest
    {
        return $this->requests[array_key_last($this->requests)] ?? throw new RuntimeException('No request was sent.');
    }
}
