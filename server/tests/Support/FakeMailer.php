<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Support;

use Maguari\Server\Notifications\Email;
use Maguari\Server\Notifications\Mailer;
use Maguari\Server\Notifications\SendFailure;
use Maguari\Server\Notifications\SmtpSettings;

/**
 * Records what would be sent, or fails with the queued failure.
 */
final class FakeMailer implements Mailer
{
    /** @var list<array{settings: SmtpSettings, email: Email, localHostname: string}> */
    public array $sent = [];
    public ?SendFailure $failure = null;

    public function send(SmtpSettings $settings, Email $email, string $localHostname): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->sent[] = ['settings' => $settings, 'email' => $email, 'localHostname' => $localHostname];
    }
}
