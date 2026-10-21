<?php

declare(strict_types=1);

namespace Maguari\Client;

use Maguari\Shared\DatabaseCheck;

/**
 * maguari-client's commands. Every failure is one line on stderr with exit
 * code 1, never a stack trace.
 */
final class Cli
{
    private const USAGE = "Usage:\n"
        . "  maguari-client enroll --server=https://maguari.example.com --token=<token>\n"
        . "  maguari-client heartbeat\n"
        . "  maguari-client run\n"
        . "  maguari-client set-database <name> --type=postgresql|mysql --host=<host> [--port=<port>] --database=<database> --user=<user>\n"
        . "  maguari-client remove-database <name>\n"
        . "  maguari-client list-databases\n"
        . "  maguari-client check-database <name>\n";

    private const COMMANDS = ['enroll', 'heartbeat', 'run', 'set-database', 'remove-database', 'list-databases', 'check-database'];

    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly Clock $clock,
        private readonly DiskUsage $diskUsage,
        private readonly CertificateExpiry $certificates,
        private readonly CredentialsFile $credentialsFile,
        private readonly Systemctl $systemctl,
        private readonly int $effectiveUserId,
        private $stdout,
        private $stderr,
        private readonly LocalHttp $localHttp = new StreamLocalHttp(),
        private readonly DatabaseConnector $databases = new PdoDatabaseConnector(),
        /** @var resource|null where set-database reads the password; null for STDIN */
        private $stdin = null,
        /** Whether $stdin is a terminal, where the password is typed without echo. */
        private readonly bool $stdinIsTerminal = false,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            new StreamTransport(),
            new SystemClock(),
            new DiskUsage(new StatvfsFilesystemStats()),
            CertificateExpiry::fromEnvironment(),
            CredentialsFile::fromEnvironment(),
            new ProcessSystemctl(),
            posix_geteuid(),
            STDOUT,
            STDERR,
            new StreamLocalHttp(),
            new PdoDatabaseConnector(),
            STDIN,
            posix_isatty(STDIN),
        );
    }

    /**
     * @param string[] $argv
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? null;
        $options = self::options(array_slice($argv, 2));

        if (!in_array($command, self::COMMANDS, true)) {
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
                'set-database' => $this->setDatabase($argv[2] ?? '', $options),
                'remove-database' => $this->removeDatabase($argv[2] ?? ''),
                'list-databases' => $this->listDatabases(),
                'check-database' => $this->checkDatabase($argv[2] ?? ''),
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
        $notice = $this->sender()->send($credentials);
        fwrite($this->stdout, sprintf("Heartbeat accepted by %s.\n", $credentials->serverUrl) . ($notice === null ? '' : $notice . "\n"));

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
        (new Runner($this->sender(), $this->clock, $log))->run($credentials);

        return 0;
    }

    private function sender(): HeartbeatSender
    {
        return new HeartbeatSender(
            $this->transport,
            $this->clock,
            $this->diskUsage,
            $this->certificates,
            new CheckSettingsFile($this->credentialsFile->directory()),
            new LocalChecks($this->systemctl, $this->clock, $this->localHttp, $this->databasesFile(), $this->databases),
        );
    }

    private function databasesFile(): DatabasesFile
    {
        return new DatabasesFile($this->credentialsFile->directory());
    }

    /**
     * Saves how to log in to a database, so the web app can name it for a
     * database check (design section 6.1.3). The password is read from
     * standard input, without echo on a terminal, never from the command
     * line, where ps and the shell's history would show it.
     *
     * @param array<string, string> $options
     * @throws ClientFailure
     */
    private function setDatabase(string $name, array $options): int
    {
        if (!DatabaseCheck::isValidName($name)) {
            throw new ClientFailure('Name the database first, as the web app will: 1 to 63 lowercase letters, digits, _ and -, for example: maguari-client set-database geonode --type=postgresql ...');
        }

        $type = $options['type'] ?? '';

        if (!isset(DatabaseCheck::DRIVERS[$type])) {
            throw new ClientFailure((string) DatabaseConnection::problem($type, '', 0, '', '', ''));
        }

        $port = $options['port'] ?? (string) (DatabaseCheck::DEFAULT_PORTS[$type] ?? '');

        if (!isset($options['host'], $options['database'], $options['user']) || preg_match('/^[0-9]{1,5}$/D', $port) !== 1) {
            fwrite($this->stderr, self::USAGE);

            return 1;
        }

        // Checked before the password is asked for, so a typo costs nothing.
        $problem = DatabaseConnection::problem($type, $options['host'], (int) $port, $options['database'], $options['user'], '');

        if ($problem !== null) {
            throw new ClientFailure($problem);
        }

        $password = $this->readPassword($options['user']);
        $problem = DatabaseConnection::problem($type, $options['host'], (int) $port, $options['database'], $options['user'], $password);

        if ($problem !== null) {
            throw new ClientFailure($problem);
        }

        $file = $this->databasesFile();
        $connections = $file->load();
        $connections[$name] = new DatabaseConnection($type, $options['host'], (int) $port, $options['database'], $options['user'], $password);
        $file->save($connections);
        fwrite($this->stdout, sprintf("Saved the database %s (%s) to %s, readable only by this user.\n", $name, $connections[$name]->describe(), $file->path()));

        if ($this->databases instanceof PdoDatabaseConnector && !$this->databases->hasDriver($type)) {
            fwrite($this->stdout, sprintf("The %s driver is not installed, so the check cannot run yet. Install it with: sudo apt install %s\n", $type, DatabaseCheck::PACKAGES[$type]));
        }

        fwrite($this->stdout, sprintf("Try it with: maguari-client check-database %s\n", $name));

        return 0;
    }

    /**
     * @throws ClientFailure
     */
    private function readPassword(string $user): string
    {
        $stdin = $this->stdin ?? STDIN;

        if ($this->stdinIsTerminal) {
            fwrite($this->stderr, sprintf('Password for %s (typing is not shown; leave empty for none): ', $user));
            $this->stty('-echo');
        }

        try {
            $line = fgets($stdin);
        } finally {
            if ($this->stdinIsTerminal) {
                $this->stty('echo');
                fwrite($this->stderr, "\n");
            }
        }

        return $line === false ? '' : rtrim($line, "\r\n");
    }

    /**
     * Turns the terminal's echo off or on, with an argument array, never a shell.
     */
    private function stty(string $setting): void
    {
        $process = @proc_open(['stty', $setting], [0 => $this->stdin ?? STDIN, 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

        if (is_resource($process)) {
            proc_close($process);
        }
    }

    /**
     * @throws ClientFailure
     */
    private function removeDatabase(string $name): int
    {
        $file = $this->databasesFile();
        $connections = $file->load();

        if (!isset($connections[$name])) {
            throw new ClientFailure(sprintf('No database named %s is set up. See them with: maguari-client list-databases', $name));
        }

        unset($connections[$name]);
        $file->save($connections);
        fwrite($this->stdout, sprintf("Removed the database %s. Remove its check in the web app too, or it stays not checked.\n", $name));

        return 0;
    }

    /**
     * Names, types and hosts, never passwords.
     *
     * @throws ClientFailure
     */
    private function listDatabases(): int
    {
        $file = $this->databasesFile();
        $connections = $file->load();

        if ($connections === []) {
            fwrite($this->stdout, sprintf("No databases are set up in %s. Add one with maguari-client set-database.\n", $file->path()));

            return 0;
        }

        foreach ($connections as $name => $connection) {
            fwrite($this->stdout, sprintf("%s: %s\n", $name, $connection->describe()));
        }

        return 0;
    }

    /**
     * Runs the check once, as a heartbeat would, to try a setup before adding
     * it in the web app. Exit code 0 only when it passes.
     *
     * @throws ClientFailure
     */
    private function checkDatabase(string $name): int
    {
        $connection = $this->databasesFile()->load()[$name] ?? throw new ClientFailure(sprintf('No database named %s is set up. Add it with: maguari-client set-database %s ...', $name, $name));
        $answer = $this->databases->check($connection);

        $line = match ($answer) {
            DatabaseCheck::REASON_DRIVER_MISSING => sprintf('Not checked: the %s driver is not installed. Install it with: sudo apt install %s', $connection->type, DatabaseCheck::PACKAGES[$connection->type]),
            DatabaseCheck::REASON_LOGIN_REFUSED => 'Fail: the server refused the login. Check the user and password, and that the server lets this user in from here (pg_hba.conf for PostgreSQL).',
            DatabaseCheck::REASON_NO_DATABASE => sprintf('Fail: the server has no database %s.', $connection->database),
            DatabaseCheck::REASON_TIMEOUT => sprintf('Fail: no answer within %d seconds.', DatabaseCheck::TIMEOUT_SECONDS),
            DatabaseCheck::REASON_QUERY_FAILED => 'Fail: the login worked, but SELECT 1 failed.',
            DatabaseCheck::REASON_UNREACHABLE => 'Fail: could not connect to the server, or it is not accepting connections.',
            default => sprintf('Pass: SELECT 1 answered in %d ms.', $answer),
        };
        fwrite($this->stdout, sprintf("%s (%s): %s\n", $name, $connection->describe(), $line));

        return is_int($answer) ? 0 : 1;
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
