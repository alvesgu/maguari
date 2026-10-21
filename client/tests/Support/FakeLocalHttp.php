<?php

declare(strict_types=1);

namespace Maguari\Client\Tests\Support;

use Maguari\Client\LocalHttp;

/**
 * Answers by URL and host name without the network: a status, or a reason
 * string. Anything not set up answers 200.
 */
final class FakeLocalHttp implements LocalHttp
{
    /** @var array<string, int|string> */
    private array $answers = [];

    /** @var list<array{string, string}> */
    public array $requests = [];

    public function answer(string $url, string $hostName, int|string $answer): void
    {
        $this->answers[$url . ' ' . $hostName] = $answer;
    }

    public function request(string $url, string $hostName): array|string
    {
        $this->requests[] = [$url, $hostName];
        $answer = $this->answers[$url . ' ' . $hostName] ?? 200;

        return is_string($answer) ? $answer : ['status' => $answer, 'ms' => 12];
    }
}
