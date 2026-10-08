<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Kernel\Secrets\SecretBox;
use Maguari\Server\Kernel\Secrets\SecretDecryptionFailed;
use Maguari\Server\Notifications\Exception\InvalidSmtpSettings;

/**
 * The Notifications context's public interface (design section 2.1). So far it
 * owns the SMTP settings (design section 13); the SMTP password is encrypted
 * at rest (design section 9.3).
 */
final class NotificationsApi
{
    public const PASSWORD_UNREADABLE = 'The stored SMTP password cannot be decrypted with this server\'s secret key. '
        . 'Enter the password again and save.';

    private readonly SmtpSettingsRepository $smtpSettings;

    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
        private readonly SecretBox $secretBox,
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
     * stored one; an empty username removes it.
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
     * @param array{password_ciphertext: ?string}|null $stored
     * @throws InvalidSmtpSettings
     */
    private function store(SmtpSettingsInput $input, ?array $stored): void
    {
        $storedCiphertext = $stored['password_ciphertext'] ?? null;
        $valid = SmtpSettingsRules::check($input, $storedCiphertext !== null);

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
