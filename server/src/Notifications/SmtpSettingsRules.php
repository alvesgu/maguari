<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

use Maguari\Server\Notifications\Exception\InvalidSmtpSettings;

/**
 * What SMTP settings may be saved (design section 13). The Email page and the
 * seed file's [smtp] section go through the same rules.
 */
final class SmtpSettingsRules
{
    public const LOCALHOST = 'localhost';
    public const DEFAULT_PORT = 587;
    /** Compute Engine blocks outbound connections to port 25. */
    public const BLOCKED_PORT = 25;
    public const USERNAME_MAX_BYTES = 254;
    public const PASSWORD_MAX_BYTES = 1024;
    private const HOST_MAX_BYTES = 253;
    private const EMAIL_MAX_BYTES = 254;

    /**
     * Normalizes and checks everything but the password's presence, which the
     * caller decides with what is stored. An empty port means the default.
     *
     * @return array{host: string, port: int, username: string, fromAddress: string}
     * @throws InvalidSmtpSettings
     */
    public static function check(SmtpSettingsInput $input, bool $passwordStored): array
    {
        $errors = [];
        $host = strtolower(trim($input->host));
        $port = trim($input->port);
        $username = trim($input->username);
        $fromAddress = strtolower(trim($input->fromAddress));

        if ($host === '') {
            $errors['host'] = 'Enter the SMTP server\'s host name, for example smtp.gmail.com.';
        } elseif (!self::isHostName($host)) {
            $errors['host'] = 'Enter a host name such as smtp.gmail.com, without a scheme, port or path. '
                . 'IP addresses are not accepted.';
        }

        if ($port === '') {
            $port = (string) self::DEFAULT_PORT;
        }

        if (preg_match('/^[0-9]{1,5}$/', $port) !== 1 || (int) $port < 1 || (int) $port > 65535) {
            $errors['port'] = sprintf('Enter a port from 1 to 65535. The usual one is %d.', self::DEFAULT_PORT);
        } elseif ((int) $port === self::BLOCKED_PORT) {
            $errors['port'] = sprintf('Compute Engine blocks outbound port %d. Use port %d.', self::BLOCKED_PORT, self::DEFAULT_PORT);
        }

        if (strlen($username) > self::USERNAME_MAX_BYTES || self::hasControlCharacters($username)) {
            $errors['username'] = sprintf(
                'Enter a username of up to %d characters, or leave it empty for a server that needs no sign-in.',
                self::USERNAME_MAX_BYTES,
            );
        }

        if ($username !== '') {
            if (strlen($input->password) > self::PASSWORD_MAX_BYTES || self::hasControlCharacters($input->password)) {
                $errors['password'] = 'Enter a password of up to 1,024 characters, without line breaks.';
            } elseif ($input->password === '' && !$passwordStored) {
                $errors['password'] = 'Enter the password for this username.';
            }
        }

        if (
            $fromAddress === ''
            || strlen($fromAddress) > self::EMAIL_MAX_BYTES
            || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false
        ) {
            $errors['from_address'] = 'Enter the address emails are sent from, for example alerts@example.com.';
        }

        if ($errors !== []) {
            throw new InvalidSmtpSettings($errors);
        }

        return ['host' => $host, 'port' => (int) $port, 'username' => $username, 'fromAddress' => $fromAddress];
    }

    /**
     * A DNS name with at least two labels of letters, digits and hyphens (not
     * at either end), whose last label is not all digits, or exactly
     * localhost.
     */
    private static function isHostName(string $host): bool
    {
        if ($host === self::LOCALHOST) {
            return true;
        }

        if (strlen($host) > self::HOST_MAX_BYTES) {
            return false;
        }

        $labels = explode('.', $host);

        if (count($labels) < 2 || preg_match('/^[0-9]+$/', end($labels)) === 1) {
            return false;
        }

        foreach ($labels as $label) {
            if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    private static function hasControlCharacters(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }
}
