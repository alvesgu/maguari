<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Kernel\Secrets\SecretBox;
use Maguari\Server\Kernel\Secrets\SecretDecryptionFailed;
use Maguari\Server\Notifications\Exception\EmailNotSent;
use Maguari\Server\Notifications\Exception\EmailNotSetUp;
use Maguari\Server\Notifications\Exception\InvalidSmtpSettings;

/**
 * The Notifications context's public interface (design section 2.1). So far it
 * owns the SMTP settings and sends the test email (design section 13); the
 * SMTP password is encrypted at rest (design section 9.3).
 */
final class NotificationsApi
{
    public const PASSWORD_UNREADABLE = 'The stored SMTP password cannot be decrypted with this server\'s secret key. '
        . 'Enter the password again and save.';
    public const NOT_SET_UP = 'Email is not set up yet. Fill in the SMTP settings and save them first.';
    public const TEST_EMAIL_SUBJECT = 'Maguari test email';

    private readonly SmtpSettingsRepository $smtpSettings;

    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
        private readonly SecretBox $secretBox,
        private readonly Mailer $mailer = new SmtpMailer(),
    ) {
        $this->smtpSettings = new SmtpSettingsRepository($database);
    }

    /**
     * Null while email is not set up.
     */
    public function smtpSettings(): ?SmtpSettingsSummary
    {
        $row = $this->smtpSettings->find();

        if ($row === null) {
            return null;
        }

        $ciphertext = $row['password_ciphertext'];

        return new SmtpSettingsSummary(
            $row['host'],
            $row['port'],
            $row['username'],
            $ciphertext !== null,
            $ciphertext === null || $this->canDecrypt($ciphertext),
            $row['from_address'],
            $row['updated_at'],
        );
    }

    /**
     * Saves the settings from the Email page. An empty password keeps the
     * stored one while the username stays the same; an empty username removes
     * it.
     *
     * @throws InvalidSmtpSettings
     */
    public function saveSmtpSettings(SmtpSettingsInput $input): void
    {
        $this->database->transaction(function () use ($input): void {
            $this->store($input, $this->smtpSettings->find());
        });
    }

    /**
     * Saves the seed file's [smtp] section (design section 11.5.1), only while
     * no settings are stored: once they are, the web app owns them.
     *
     * @return bool false when settings were already stored, and nothing changed
     * @throws InvalidSmtpSettings
     */
    public function importSmtpSettings(SmtpSettingsInput $input): bool
    {
        return $this->database->transaction(function () use ($input): bool {
            if ($this->smtpSettings->find() !== null) {
                return false;
            }

            $this->store($input, null);

            return true;
        });
    }

    /**
     * Sends the test email with the saved settings, inside the request.
     *
     * @param string $recipient for now the signed-in administrator's address
     * @param string|null $baseUrl the server's configured address, named in
     *                             the body and used as its hostname in EHLO
     * @return string the sentence saying it was sent
     * @throws EmailNotSetUp
     * @throws EmailNotSent with the sentence for the administrator and a line for the log
     */
    public function sendTestEmail(string $recipient, string $administratorName, ?string $baseUrl): string
    {
        $settings = $this->settingsForSending();
        $now = $this->clock->now();
        $from = $baseUrl === null ? '' : ' at ' . $baseUrl;
        $email = new Email(
            $recipient,
            self::TEST_EMAIL_SUBJECT,
            sprintf(
                "This is a test email from Maguari%s, sent at %s UTC by %s.\n\nAlerts will be sent the same way.\n",
                $from,
                gmdate('Y-m-d H:i', $now),
                $administratorName,
            ),
        );
        $localHostname = ($baseUrl === null ? null : parse_url($baseUrl, PHP_URL_HOST)) ?: (gethostname() ?: 'localhost');

        try {
            $this->mailer->send($settings, $email, $localHostname);
        } catch (SendFailure $failure) {
            throw new EmailNotSent(
                SendFailureSentence::for($failure, $settings, $recipient),
                'The test email failed at ' . $failure->detail,
                $failure,
            );
        }

        return sprintf(
            'Sent a test email to %s through %s at %s UTC. If it does not arrive within a few minutes, check the spam folder.',
            $recipient,
            $settings->host,
            gmdate('H:i', $now),
        );
    }

    /**
     * @throws EmailNotSetUp
     * @throws EmailNotSent when the stored password cannot be decrypted
     */
    private function settingsForSending(): SmtpSettings
    {
        $row = $this->smtpSettings->find() ?? throw new EmailNotSetUp(self::NOT_SET_UP);
        $password = '';

        if ($row['password_ciphertext'] !== null) {
            try {
                $password = $this->secretBox->decrypt($row['password_ciphertext']);
            } catch (SecretDecryptionFailed $failed) {
                throw new EmailNotSent(self::PASSWORD_UNREADABLE, 'The test email was not sent: the stored SMTP password cannot be decrypted.', $failed);
            }
        }

        return new SmtpSettings(
            $row['host'],
            $row['port'],
            SmtpEncryption::for($row['host'], $row['port']),
            $row['username'],
            $password,
            $row['from_address'],
        );
    }

    /**
     * @param array{username: string, password_ciphertext: ?string}|null $stored
     * @throws InvalidSmtpSettings
     */
    private function store(SmtpSettingsInput $input, ?array $stored): void
    {
        $storedCiphertext = $stored['password_ciphertext'] ?? null;
        $valid = SmtpSettingsRules::check($input, $storedCiphertext === null ? null : $stored['username']);

        if ($valid['username'] === '') {
            $ciphertext = null;
        } elseif ($input->password !== '') {
            $ciphertext = $this->secretBox->encrypt($input->password);
        } else {
            $ciphertext = $storedCiphertext;
        }

        $this->smtpSettings->save(
            $valid['host'],
            $valid['port'],
            $valid['username'],
            $ciphertext,
            $valid['fromAddress'],
            $this->clock->now(),
        );
    }

    private function canDecrypt(string $ciphertext): bool
    {
        try {
            $this->secretBox->decrypt($ciphertext);

            return true;
        } catch (SecretDecryptionFailed) {
            return false;
        }
    }
}
