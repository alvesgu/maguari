<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Notifications;

use Maguari\Server\Notifications\Exception\InvalidSmtpSettings;
use Maguari\Server\Notifications\SmtpEncryption;
use Maguari\Server\Notifications\SmtpSettingsInput;
use Maguari\Server\Notifications\SmtpSettingsRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SmtpSettingsRulesTest extends TestCase
{
    private static function input(
        string $host = 'smtp.gmail.com',
        string $port = '587',
        string $username = 'alerts@example.com',
        string $password = 'app password',
        string $fromAddress = 'alerts@example.com',
    ): SmtpSettingsInput {
        return new SmtpSettingsInput($host, $port, $username, $password, $fromAddress);
    }

    /**
     * @return array<string, string>
     */
    private static function errors(SmtpSettingsInput $input, bool $passwordStored = false): array
    {
        try {
            SmtpSettingsRules::check($input, $passwordStored);
        } catch (InvalidSmtpSettings $invalid) {
            return $invalid->errors;
        }

        return [];
    }

    public function testNormalizesValidSettings(): void
    {
        $valid = SmtpSettingsRules::check(self::input(' SMTP.Gmail.com ', ' 587 ', ' alerts@example.com ', 'p', ' Alerts@Example.com '), false);

        $this->assertSame(['host' => 'smtp.gmail.com', 'port' => 587, 'username' => 'alerts@example.com', 'fromAddress' => 'alerts@example.com'], $valid);
    }

    public function testAnEmptyPortIsTheDefault(): void
    {
        $this->assertSame(587, SmtpSettingsRules::check(self::input(port: ''), false)['port']);
    }

    public function testAcceptsLocalhostAndNoUsername(): void
    {
        $valid = SmtpSettingsRules::check(self::input('localhost', '1025', '', ''), false);

        $this->assertSame('localhost', $valid['host']);
        $this->assertSame('', $valid['username']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidHosts(): array
    {
        return [
            'scheme' => ['smtp://smtp.gmail.com'],
            'port' => ['smtp.gmail.com:587'],
            'path' => ['smtp.gmail.com/x'],
            'one label' => ['mailserver'],
            'IPv4 address' => ['192.0.2.1'],
            'IPv6 address' => ['[::1]'],
            'leading hyphen' => ['-smtp.example.com'],
            'trailing hyphen' => ['smtp-.example.com'],
            'empty label' => ['smtp..example.com'],
            'trailing dot' => ['smtp.example.com.'],
            'underscore' => ['smtp_relay.example.com'],
            'label over 63 bytes' => [str_repeat('a', 64) . '.example.com'],
            'over 253 bytes' => [implode('.', array_fill(0, 5, str_repeat('a', 60))) . '.com'],
            'wildcard' => ['*.example.com'],
            'space inside' => ['smtp .example.com'],
        ];
    }

    #[DataProvider('invalidHosts')]
    public function testRejectsInvalidHosts(string $host): void
    {
        $this->assertSame(
            ['host' => 'Enter a host name such as smtp.gmail.com, without a scheme, port or path. IP addresses are not accepted.'],
            self::errors(self::input(host: $host)),
        );
    }

    public function testRequiresAHost(): void
    {
        $this->assertSame(['host' => 'Enter the SMTP server\'s host name, for example smtp.gmail.com.'], self::errors(self::input(host: ' ')));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPorts(): array
    {
        return [
            'zero' => ['0'],
            'too high' => ['65536'],
            'negative' => ['-1'],
            'not a number' => ['smtp'],
            'decimal' => ['587.0'],
            'six digits' => ['000587'],
        ];
    }

    #[DataProvider('invalidPorts')]
    public function testRejectsInvalidPorts(string $port): void
    {
        $this->assertSame(['port' => 'Enter a port from 1 to 65535. The usual one is 587.'], self::errors(self::input(port: $port)));
    }

    public function testRefusesPort25(): void
    {
        $this->assertSame(['port' => 'Compute Engine blocks outbound port 25. Use port 587.'], self::errors(self::input(port: '25')));
    }

    public function testRejectsLongOrControlCharacterUsernames(): void
    {
        $expected = ['username' => 'Enter a username of up to 254 characters, or leave it empty for a server that needs no sign-in.'];

        $this->assertSame($expected, self::errors(self::input(username: str_repeat('a', 255))));
        $this->assertSame($expected, self::errors(self::input(username: "alerts\r\n@example.com")));
        $this->assertSame([], self::errors(self::input(username: str_repeat('a', 254))));
    }

    public function testAUsernameNeedsAPasswordUnlessOneIsStored(): void
    {
        $this->assertSame(['password' => 'Enter the password for this username.'], self::errors(self::input(password: '')));
        $this->assertSame([], self::errors(self::input(password: ''), true));
    }

    public function testRejectsLongOrControlCharacterPasswords(): void
    {
        $expected = ['password' => 'Enter a password of up to 1,024 characters, without line breaks.'];

        $this->assertSame($expected, self::errors(self::input(password: str_repeat('a', 1025))));
        $this->assertSame($expected, self::errors(self::input(password: "secret\n")));
        $this->assertSame([], self::errors(self::input(password: str_repeat('a', 1024))));
        $this->assertSame([], self::errors(self::input(password: ' spaces are kept ')));
    }

    public function testThePasswordIsIgnoredWithoutAUsername(): void
    {
        $this->assertSame([], self::errors(self::input(username: '', password: "anything\n")));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidFromAddresses(): array
    {
        return [
            'empty' => [''],
            'no domain' => ['alerts'],
            'display name' => ['Maguari <alerts@example.com>'],
            'header injection' => ["alerts@example.com\r\nBcc: x@example.com"],
            'too long' => [str_repeat('a', 64) . '@' . str_repeat('b', 186) . '.com'],
        ];
    }

    #[DataProvider('invalidFromAddresses')]
    public function testRejectsInvalidFromAddresses(string $fromAddress): void
    {
        $this->assertSame(
            ['from_address' => 'Enter the address emails are sent from, for example alerts@example.com.'],
            self::errors(self::input(fromAddress: $fromAddress)),
        );
    }

    public function testReportsEveryFieldAtOnce(): void
    {
        $this->assertSame(['host', 'port', 'password', 'from_address'], array_keys(self::errors(self::input('', '25', 'a', '', ''))));
    }

    public function testEncryptionFollowsHostAndPort(): void
    {
        $this->assertSame(SmtpEncryption::StartTls, SmtpEncryption::for('smtp.gmail.com', 587));
        $this->assertSame(SmtpEncryption::StartTls, SmtpEncryption::for('smtp-relay.gmail.com', 2525));
        $this->assertSame(SmtpEncryption::ImplicitTls, SmtpEncryption::for('smtp.gmail.com', 465));
        $this->assertSame(SmtpEncryption::ImplicitTls, SmtpEncryption::for('localhost', 465));
        $this->assertSame(SmtpEncryption::None, SmtpEncryption::for('localhost', 1025));
        $this->assertSame(SmtpEncryption::StartTls, SmtpEncryption::for('localhost.example.com', 1025));
    }
}
