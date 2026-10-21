<?php

declare(strict_types=1);

namespace Maguari\Client;

/**
 * Reads a unit's state from systemd, without privileges (design section 5.4).
 */
interface Systemctl
{
    /**
     * @return ?array<string, string> LoadState, ActiveState and SubState; null
     *         when systemctl could not be run or did not answer in time
     */
    public function show(string $unit): ?array;
}
