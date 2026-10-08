<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications\Exception;

/**
 * SMTP settings that cannot be saved. Each message is a fixed sentence for the
 * administrator.
 */
final class InvalidSmtpSettings extends \RuntimeException
{
    /**
     * @param array<string, string> $errors messages keyed by field name: host,
     *                                      port, username, password, from_address
     */
    public function __construct(
        public readonly array $errors,
    ) {
        parent::__construct('The SMTP settings have errors.');
    }
}
