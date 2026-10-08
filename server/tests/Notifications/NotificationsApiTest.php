<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Notifications;

use Maguari\Server\Kernel\Secrets\SecretBox;
use Maguari\Server\Notifications\Exception\InvalidSmtpSettings;
use Maguari\Server\Notifications\NotificationsApi;
use Maguari\Server\Notifications\SmtpSettingsInput;
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
}
