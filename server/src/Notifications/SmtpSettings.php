<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

/**
 * The stored SMTP settings with the decrypted password, for sending. It lives
 * only in memory, for one send.
 */
final class SmtpSettings
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly SmtpEncryption $encryption,
        public readonly string $username,
        #[\SensitiveParameter]
        public readonly string $password,
        public readonly string $fromAddress,
    ) {
    }

    /**
     * Keeps the password out of var_dump() and print_r().
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'encryption' => $this->encryption,
            'username' => $this->username,
            'password' => $this->password === '' ? '' : '(hidden)',
            'fromAddress' => $this->fromAddress,
        ];
    }
}
