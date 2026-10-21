<?php

declare(strict_types=1);

namespace Maguari\Client;

/**
 * Runs `systemctl show --property=LoadState,ActiveState,SubState -- <unit>`
 * with an argument array, never through a shell, and gives up after a few
 * seconds, so a stuck systemd cannot hold up the heartbeat.
 */
final class ProcessSystemctl implements Systemctl
{
    public const TIMEOUT_SECONDS = 5;

    /**
     * @param string $binary another program in its place, for tests only
     */
    public function __construct(
        private readonly string $binary = 'systemctl',
        private readonly float $timeoutSeconds = self::TIMEOUT_SECONDS,
    ) {
    }

    public function show(string $unit): ?array
    {
        $process = @proc_open(
            [$this->binary, 'show', '--property=LoadState,ActiveState,SubState', '--', $unit],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            return null;
        }

        stream_set_blocking($pipes[1], false);
        $deadline = microtime(true) + $this->timeoutSeconds;
        $output = '';

        while (!feof($pipes[1])) {
            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                fclose($pipes[1]);
                proc_terminate($process, 9);
                proc_close($process);

                return null;
            }

            $read = [$pipes[1]];
            $none = null;
            @stream_select($read, $none, $none, 0, (int) (min($remaining, 0.25) * 1_000_000));
            $output .= (string) fread($pipes[1], 8192);
        }

        fclose($pipes[1]);

        if (proc_close($process) !== 0) {
            return null;
        }

        $properties = [];

        foreach (explode("\n", $output) as $line) {
            if (str_contains($line, '=')) {
                [$name, $value] = explode('=', $line, 2);
                $properties[$name] = trim($value);
            }
        }

        return isset($properties['LoadState'], $properties['ActiveState']) ? $properties : null;
    }
}
