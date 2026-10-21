<?php

declare(strict_types=1);

namespace Maguari\Client\Tests\Support;

use Maguari\Client\Systemctl;

/**
 * Units by name, without systemd. A unit not set up is not found, as
 * systemctl says. Each call can take time on a fake clock.
 */
final class FakeSystemctl implements Systemctl
{
    /** @var array<string, ?array<string, string>> */
    private array $units = [];

    /** @var list<string> */
    public array $shown = [];

    public function __construct(
        private readonly ?FakeClock $clock = null,
        private readonly int $takesSeconds = 0,
    ) {
    }

    /**
     * @param ?string $activeState null for a systemctl that fails
     */
    public function unit(string $unit, ?string $activeState, string $loadState = 'loaded'): void
    {
        $this->units[$unit] = $activeState === null ? null : ['LoadState' => $loadState, 'ActiveState' => $activeState, 'SubState' => 'running'];
    }

    public function show(string $unit): ?array
    {
        $this->shown[] = $unit;
        if ($this->clock !== null) {
            $this->clock->now += $this->takesSeconds;
        }

        return array_key_exists($unit, $this->units) ? $this->units[$unit] : ['LoadState' => 'not-found', 'ActiveState' => 'inactive', 'SubState' => 'dead'];
    }
}
