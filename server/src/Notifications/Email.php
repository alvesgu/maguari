<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

/**
 * One plain-text email, to every recipient in To. The subject stays ASCII,
 * because mbstring is not a server dependency and PHPMailer needs it to
 * encode other headers; the body may be UTF-8.
 */
final class Email
{
    /**
     * @param list<string> $recipients at least one
     */
    public function __construct(
        public readonly array $recipients,
        public readonly string $subject,
        public readonly string $body,
    ) {
        if ($recipients === []) {
            throw new \InvalidArgumentException('An email needs a recipient.');
        }
    }
}
