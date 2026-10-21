<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Support;

use Maguari\Server\Kernel\HttpProbe\HttpProbe;
use Maguari\Server\Kernel\HttpProbe\HttpProbeAnswer;
use Maguari\Server\Kernel\HttpProbe\HttpProbeFailure;
use Maguari\Server\Kernel\HttpProbe\HttpProbeFailureKind;

/**
 * Answers by URL without the network: each URL's queued answers in order,
 * the last one repeating. A URL with nothing queued answers 200. Each
 * request can take time on the test's clock.
 */
final class FakeHttpProbe implements HttpProbe
{
    /** @var array<string, list<int|HttpProbeFailureKind>> */
    private array $answers = [];

    /** @var list<array{string, string, float, float}> URL, user agent and both limits */
    public array $requests = [];

    /** Seconds each request takes on the clock. */
    public int $takesSeconds = 0;

    /** @var ?\Closure(string): void runs during each request, with its URL */
    public ?\Closure $during = null;

    public function __construct(
        private readonly FixedClock $clock,
    ) {
    }

    public function answer(string $url, int|HttpProbeFailureKind ...$answers): void
    {
        $this->answers[$url] = $answers;
    }

    public function probe(string $url, string $userAgent, float $connectSeconds, float $totalSeconds): HttpProbeAnswer
    {
        $this->requests[] = [$url, $userAgent, $connectSeconds, $totalSeconds];
        $this->clock->advance($this->takesSeconds);

        if ($this->during !== null) {
            ($this->during)($url);
        }
        $queued = $this->answers[$url] ?? [200];
        $answer = count($queued) > 1 ? array_shift($this->answers[$url]) : $queued[0];

        if ($answer instanceof HttpProbeFailureKind) {
            throw new HttpProbeFailure($answer, 'A fake failure.');
        }

        return new HttpProbeAnswer($answer, 42);
    }

    /**
     * @return list<string>
     */
    public function urls(): array
    {
        return array_column($this->requests, 0);
    }
}
