<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

/**
 * SMTP settings as typed in the Email page or read from the seed file, before
 * validation. An empty password keeps the stored one.
 */
final class SmtpSettingsInput
{
    public function __construct(
        public readonly string $host,
        public readonly string $port,
        public readonly string $username,
        #[\SensitiveParameter]
        public readonly string $password,
        public readonly string $fromAddress,
    ) {
    }

    /**
     * Keeps the password out of var_dump() and print_r().
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password === '' ? '' : '(hidden)',
            'fromAddress' => $this->fromAddress,
        ];
    }
}
