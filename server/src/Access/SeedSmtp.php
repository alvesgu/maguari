<?php

declare(strict_types=1);

namespace Maguari\Server\Access;

/**
 * The seed file's [smtp] section (design section 11.5.1), as text. Access only
 * checks that the values are text; Notifications validates them with the same
 * rules as the Email page. A key left out is ''.
 */
final class SeedSmtp
{
    public function __construct(
        public readonly string $host,
        public readonly string $port,
        public readonly string $username,
        #[\SensitiveParameter]
        public readonly string $password,
        public readonly string $from,
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
            'from' => $this->from,
        ];
    }
}
