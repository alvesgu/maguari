<?php

declare(strict_types=1);

namespace Maguari\Client;

/**
 * maguari-client's commands. Every failure is one line on stderr with exit
 * code 1, never a stack trace.
 */
final class Cli
{
    private const USAGE = "Usage:\n"
        . "  maguari-client enroll --server=https://maguari.example.com --token=<token>\n"
        . "  maguari-client heartbeat\n"
        . "  maguari-client run\n";

    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly Clock $clock,
        private readonly DiskUsage $diskUsage,
        private readonly CredentialsFile $credentialsFile,
        private readonly int $effectiveUserId,
        private $stdout,
        private $stderr,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            new StreamTransport(),
            new SystemClock(),
            new DiskUsage(new StatvfsFilesystemStats()),
            CredentialsFile::fromEnvironment(),
            posix_geteuid(),
            STDOUT,
            STDERR,
        );
    }

    /**
     * @param string[] $argv
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? null;
        $options = self::options(array_slice($argv, 2));

        if (!in_array($command, ['enroll', 'heartbeat', 'run'], true)) {
            fwrite($this->stderr, self::USAGE);

            return 1;
        }

        // Design section 5.4: the client runs as an unprivileged user, so its
        // credentials file is never owned by root.
        if ($this->effectiveUserId === 0) {
            fwrite($this->stderr, "Do not run maguari-client as root. Run it as the client's own user, for example:\n"
                . "  sudo -u maguari-client maguari-client {$command}\n");

            return 1;
        }

        try {
            return match ($command) {
                'enroll' => $this->enroll($options),
                'heartbeat' => $this->heartbeat(),
                'run' => $this->runLoop(),
            };
        } catch (ClientFailure $failure) {
            $message = $failure->getMessage();
        } catch (\Throwable $exception) {
            $message = 'maguari-client failed: ' . $exception->getMessage();
        }

        fwrite($this->stderr, str_replace(["\r", "\n"], ' ', $message) . "\n");

        return 1;
    }

    /**
     * @param array<string, string> $options
     */
    private function enroll(array $options): int
    {
        if (!isset($options['server'], $options['token'])) {
            fwrite($this->stderr, self::USAGE);

            return 1;
        }

        $credentials = (new Enroller($this->transport, $this->clock))->enroll($options['server'], $options['token']);
        // Written only after the server accepted the token, so a failed
        // enrollment leaves any earlier credentials in place.
        $this->credentialsFile->save($credentials);
        fwrite($this->stdout, sprintf(
            "Enrolled with %s. Credentials saved to %s (readable only by this user).\n",
            $credentials->serverUrl,
            $this->credentialsFile->path(),
        ));

        return 0;
    }

    private function heartbeat(): int
    {
        $credentials = $this->credentialsFile->load();
        (new HeartbeatSender($this->transport, $this->clock, $this->diskUsage))->send($credentials);
        fwrite($this->stdout, sprintf("Heartbeat accepted by %s.\n", $credentials->serverUrl));

        return 0;
    }

    private function runLoop(): int
    {
        $credentials = $this->credentialsFile->load();
        $stderr = $this->stderr;
        $clock = $this->clock;
        $log = static function (string $message) use ($stderr, $clock): void {
            fwrite($stderr, gmdate('Y-m-d H:i:s', $clock->now()) . ' UTC ' . $message . "\n");
        };
        (new Runner(new HeartbeatSender($this->transport, $this->clock, $this->diskUsage), $this->clock, $log))->run($credentials);

        return 0;
    }

    /**
     * @param string[] $args
     * @return array<string, string> --name=value options
     */
    private static function options(array $args): array
    {
        $options = [];

        foreach ($args as $arg) {
            if (preg_match('/^--([a-z]+)=(.*)$/sD', $arg, $match) === 1) {
                $options[$match[1]] = $match[2];
            }
        }

        return $options;
    }
}
