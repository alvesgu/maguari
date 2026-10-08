<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Notifications;

use Maguari\Server\Kernel\Secrets\SecretBox;
use Maguari\Server\Notifications\Exception\EmailNotSent;
use Maguari\Server\Notifications\Exception\EmailNotSetUp;
use Maguari\Server\Notifications\Exception\InvalidSmtpSettings;
use Maguari\Server\Notifications\NotificationsApi;
use Maguari\Server\Notifications\SendFailure;
use Maguari\Server\Notifications\SmtpEncryption;
use Maguari\Server\Notifications\SmtpSettingsInput;
use Maguari\Server\Notifications\SmtpStage;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

final class NotificationsApiTest extends TestCase
{
    private const PASSWORD = 'abcd efgh ijkl mnop';

    private TestEnvironment $environment;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
    }

    protected function tearDown(): void
    {
        $this->environment->cleanUp();
    }

    private static function input(string $username = 'alerts@example.com', string $password = self::PASSWORD, string $host = 'smtp.gmail.com'): SmtpSettingsInput
    {
        return new SmtpSettingsInput($host, '587', $username, $password, 'alerts@example.com');
    }

    private function storedCiphertext(): ?string
    {
        $value = $this->environment->database->pdo()->query('SELECT password_ciphertext FROM notifications_smtp_settings')->fetchColumn();

        return $value === null ? null : (string) $value;
    }

    public function testNothingIsStoredAtFirst(): void
    {
        $this->assertNull($this->environment->notifications->smtpSettings());
    }

    public function testSavesSettingsWithTheEncryptedPassword(): void
    {
        $this->environment->notifications->saveSmtpSettings(self::input());
        $settings = $this->environment->notifications->smtpSettings();
        $ciphertext = $this->storedCiphertext();

        $this->assertNotNull($settings);
        $this->assertSame('smtp.gmail.com', $settings->host);
        $this->assertSame(587, $settings->port);
        $this->assertSame('alerts@example.com', $settings->username);
        $this->assertSame('alerts@example.com', $settings->fromAddress);
        $this->assertTrue($settings->hasPassword);
        $this->assertTrue($settings->passwordReadable);
        $this->assertSame($this->environment->clock->now(), $settings->updatedAt);
        $this->assertNotNull($ciphertext);
        $this->assertStringNotContainsString(self::PASSWORD, $ciphertext);
        $this->assertSame(self::PASSWORD, $this->environment->secretBox->decrypt($ciphertext));
    }

    public function testEachSaveUsesANewNonce(): void
    {
        $this->environment->notifications->saveSmtpSettings(self::input());
        $first = $this->storedCiphertext();
        $this->environment->notifications->saveSmtpSettings(self::input());

        $this->assertNotSame($first, $this->storedCiphertext());
    }

    public function testAnEmptyPasswordKeepsTheStoredOne(): void
    {
        $this->environment->notifications->saveSmtpSettings(self::input());
        $stored = $this->storedCiphertext();
        $this->environment->notifications->saveSmtpSettings(self::input(password: '', host: 'smtp-relay.gmail.com'));

        $this->assertSame($stored, $this->storedCiphertext());
        $this->assertSame('smtp-relay.gmail.com', $this->environment->notifications->smtpSettings()?->host);
    }

    public function testANewUsernameWithoutAPasswordIsRefusedAndChangesNothing(): void
    {
        $this->environment->notifications->saveSmtpSettings(self::input());
        $stored = $this->storedCiphertext();

        try {
            $this->environment->notifications->saveSmtpSettings(self::input(username: 'other@example.com', password: ''));
            $this->fail('Expected InvalidSmtpSettings.');
        } catch (InvalidSmtpSettings $invalid) {
            $this->assertSame(['password' => 'Enter the password again: the username changed.'], $invalid->errors);
        }

        $this->assertSame($stored, $this->storedCiphertext());
        $this->assertSame('alerts@example.com', $this->environment->notifications->smtpSettings()?->username);
    }

    public function testANewPasswordReplacesTheStoredOne(): void
    {
        $this->environment->notifications->saveSmtpSettings(self::input());
        $this->environment->notifications->saveSmtpSettings(self::input(password: 'another one'));

        $this->assertSame('another one', $this->environment->secretBox->decrypt((string) $this->storedCiphertext()));
    }

    public function testAnEmptyUsernameRemovesThePassword(): void
    {
        $this->environment->notifications->saveSmtpSettings(self::input());
        $this->environment->notifications->saveSmtpSettings(self::input(username: '', password: ''));

        $this->assertNull($this->storedCiphertext());
        $this->assertFalse($this->environment->notifications->smtpSettings()?->hasPassword);
    }

    public function testInvalidSettingsStoreNothing(): void
    {
        $this->environment->notifications->saveSmtpSettings(self::input());

        try {
            $this->environment->notifications->saveSmtpSettings(self::input(host: 'not a host'));
            $this->fail('Expected InvalidSmtpSettings.');
        } catch (InvalidSmtpSettings $invalid) {
            $this->assertArrayHasKey('host', $invalid->errors);
        }

        $this->assertSame('smtp.gmail.com', $this->environment->notifications->smtpSettings()?->host);
    }

    public function testAPasswordFromAnotherKeyIsReportedAsUnreadable(): void
    {
        $otherKey = new NotificationsApi($this->environment->database, $this->environment->clock, new SecretBox(random_bytes(32)));
        $otherKey->saveSmtpSettings(self::input());
        $settings = $this->environment->notifications->smtpSettings();

        $this->assertNotNull($settings);
        $this->assertTrue($settings->hasPassword);
        $this->assertFalse($settings->passwordReadable);
    }

    public function testImportStoresOnlyWhileNothingIsStored(): void
    {
        $this->assertTrue($this->environment->notifications->importSmtpSettings(self::input()));
        $this->assertFalse($this->environment->notifications->importSmtpSettings(self::input(host: 'smtp-relay.gmail.com')));
        $this->assertSame('smtp.gmail.com', $this->environment->notifications->smtpSettings()?->host);
    }

    public function testImportUsesTheSameRules(): void
    {
        $this->expectException(InvalidSmtpSettings::class);

        try {
            $this->environment->notifications->importSmtpSettings(self::input(password: ''));
        } finally {
            $this->assertNull($this->environment->notifications->smtpSettings());
        }
    }

    public function testThePasswordStaysOutOfDumps(): void
    {
        $this->assertStringNotContainsString(self::PASSWORD, print_r(self::input(), true));
    }

    public function testSendsTheTestEmailWithTheSavedSettings(): void
    {
        $this->environment->notifications->saveSmtpSettings(self::input());
        $sentence = $this->environment->notifications->sendTestEmail('jane@example.com', 'Zoë Doe', 'https://maguari.example.com');
        $at = $this->environment->clock->now();

        $this->assertSame(sprintf(
            'Sent a test email to jane@example.com through smtp.gmail.com at %s UTC. If it does not arrive within a few minutes, check the spam folder.',
            gmdate('H:i', $at),
        ), $sentence);
        $this->assertCount(1, $this->environment->mailer->sent);
        ['settings' => $settings, 'email' => $email, 'localHostname' => $hostname] = $this->environment->mailer->sent[0];
        $this->assertSame(SmtpEncryption::StartTls, $settings->encryption);
        $this->assertSame(self::PASSWORD, $settings->password);
        $this->assertSame('jane@example.com', $email->recipient);
        $this->assertSame('Maguari test email', $email->subject);
        $this->assertSame(sprintf(
            "This is a test email from Maguari at https://maguari.example.com, sent at %s UTC by Zoë Doe.\n\nAlerts will be sent the same way.\n",
            gmdate('Y-m-d H:i', $at),
        ), $email->body);
        $this->assertSame('maguari.example.com', $hostname);
    }

    public function testWithoutABaseUrlTheBodyLeavesItOutAndEhloUsesTheMachinesName(): void
    {
        $this->environment->notifications->saveSmtpSettings(self::input());
        $this->environment->notifications->sendTestEmail('jane@example.com', 'Jane Doe', null);
        ['email' => $email, 'localHostname' => $hostname] = $this->environment->mailer->sent[0];

        $this->assertStringStartsWith('This is a test email from Maguari, sent at ', $email->body);
        $this->assertSame(gethostname(), $hostname);
    }

    public function testRefusesWhenNotSetUp(): void
    {
        $this->expectException(EmailNotSetUp::class);
        $this->expectExceptionMessage('Email is not set up yet. Fill in the SMTP settings and save them first.');

        $this->environment->notifications->sendTestEmail('jane@example.com', 'Jane Doe', null);
    }

    public function testAnUnreadablePasswordIsNotSent(): void
    {
        (new NotificationsApi($this->environment->database, $this->environment->clock, new SecretBox(random_bytes(32))))->saveSmtpSettings(self::input());

        try {
            $this->environment->notifications->sendTestEmail('jane@example.com', 'Jane Doe', null);
            $this->fail('Expected EmailNotSent.');
        } catch (EmailNotSent $notSent) {
            $this->assertSame(NotificationsApi::PASSWORD_UNREADABLE, $notSent->getMessage());
        }

        $this->assertSame([], $this->environment->mailer->sent);
    }

    public function testAFailureGivesItsSentenceAndALogLine(): void
    {
        $this->environment->notifications->saveSmtpSettings(self::input());
        $this->environment->mailer->failure = new SendFailure(SmtpStage::Authenticate, 535, 'AUTH: reply 535');

        try {
            $this->environment->notifications->sendTestEmail('jane@example.com', 'Jane Doe', null);
            $this->fail('Expected EmailNotSent.');
        } catch (EmailNotSent $notSent) {
            $this->assertSame('smtp.gmail.com did not accept the username and password (reply 535). For Gmail, use an app password, not the account password.', $notSent->getMessage());
            $this->assertSame('The test email failed at AUTH: reply 535', $notSent->logLine);
            $this->assertStringNotContainsString(self::PASSWORD, $notSent->getMessage() . $notSent->logLine);
        }
    }
}
