<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications\Exception;

/**
 * Sending failed. The message is a fixed sentence for the administrator;
 * $logLine is one line for the error log, which the caller writes. Neither
 * holds the SMTP password.
 */
final class EmailNotSent extends \RuntimeException
{
    public function __construct(
        string $sentence,
        public readonly string $logLine,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($sentence, 0, $previous);
    }
}
