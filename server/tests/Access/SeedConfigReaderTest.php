<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Access;

use Maguari\Server\Access\Exception\InvalidSeedConfig;
use Maguari\Server\Access\SeedConfigReader;
use PHPUnit\Framework\TestCase;

final class SeedConfigReaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/maguari-seed-test-' . bin2hex(random_bytes(8));
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->tempDir);
    }

    private function writeIni(string $contents): string
    {
        $path = $this->tempDir . '/seed.ini';
        file_put_contents($path, $contents);

        return $path;
    }

    public function testAbsentFileReturnsNull(): void
    {
        $reader = new SeedConfigReader($this->tempDir . '/does-not-exist.ini');

        self::assertNull($reader->read());
    }

    public function testValidFileWithExplicitAllowlist(): void
    {
        $path = $this->writeIni(<<<INI
            [administrator]
            name = "Jane Doe"
            email = jane@example.com

            [access]
            allowlist[] = jane@example.com
            allowlist[] = ops@example.com
            INI);

        $seedConfig = (new SeedConfigReader($path))->read();

        self::assertNotNull($seedConfig);
        self::assertSame('Jane Doe', $seedConfig->administratorName);
        self::assertSame('jane@example.com', $seedConfig->administratorEmail);
        self::assertSame(['jane@example.com', 'ops@example.com'], $seedConfig->allowlist);
    }

    public function testValidFileWithoutAllowlistDefaultsToAdministratorEmail(): void
    {
        $path = $this->writeIni(<<<INI
            [administrator]
            name = "Jane Doe"
            email = jane@example.com
            INI);

        $seedConfig = (new SeedConfigReader($path))->read();

        self::assertNotNull($seedConfig);
        self::assertSame(['jane@example.com'], $seedConfig->allowlist);
    }

    public function testAllowlistIsLowercasedAndDeduplicated(): void
    {
        $path = $this->writeIni(<<<INI
            [administrator]
            name = "Jane Doe"
            email = Jane@Example.com

            [access]
            allowlist[] = Jane@Example.com
            allowlist[] = jane@example.com
            allowlist[] = ops@example.com
            INI);

        $seedConfig = (new SeedConfigReader($path))->read();

        self::assertNotNull($seedConfig);
        self::assertSame('jane@example.com', $seedConfig->administratorEmail);
        self::assertSame(['jane@example.com', 'ops@example.com'], $seedConfig->allowlist);
    }

    public function testUnparsableIniThrowsCleanError(): void
    {
        $path = $this->writeIni("[administrator\nname = \"Jane\"");

        $reader = new SeedConfigReader($path);

        try {
            $reader->read();
            self::fail('Expected InvalidSeedConfig to be thrown.');
        } catch (InvalidSeedConfig $exception) {
            self::assertStringContainsString($path, $exception->getMessage());
            self::assertStringNotContainsString('Warning', $exception->getMessage());
            self::assertStringNotContainsString('failed to open', $exception->getMessage());
        }
    }

    public function testMissingAdministratorSectionThrows(): void
    {
        $path = $this->writeIni(<<<INI
            [access]
            allowlist[] = jane@example.com
            INI);

        $this->expectException(InvalidSeedConfig::class);

        (new SeedConfigReader($path))->read();
    }

    public function testMissingAdministratorNameThrows(): void
    {
        $path = $this->writeIni(<<<INI
            [administrator]
            email = jane@example.com
            INI);

        $this->expectException(InvalidSeedConfig::class);

        (new SeedConfigReader($path))->read();
    }

    public function testMissingAdministratorEmailThrows(): void
    {
        $path = $this->writeIni(<<<INI
            [administrator]
            name = "Jane Doe"
            INI);

        $this->expectException(InvalidSeedConfig::class);

        (new SeedConfigReader($path))->read();
    }

    public function testInvalidAdministratorEmailThrows(): void
    {
        $path = $this->writeIni(<<<INI
            [administrator]
            name = "Jane Doe"
            email = not-an-email
            INI);

        $this->expectException(InvalidSeedConfig::class);

        (new SeedConfigReader($path))->read();
    }

    public function testInvalidAllowlistEmailThrows(): void
    {
        $path = $this->writeIni(<<<INI
            [administrator]
            name = "Jane Doe"
            email = jane@example.com

            [access]
            allowlist[] = not-an-email
            INI);

        $this->expectException(InvalidSeedConfig::class);

        (new SeedConfigReader($path))->read();
    }

    public function testUnquotedBooleanLikeNameSuggestsQuoting(): void
    {
        $path = $this->writeIni(<<<INI
            [administrator]
            name = no
            email = jane@example.com
            INI);

        try {
            (new SeedConfigReader($path))->read();
            self::fail('Expected InvalidSeedConfig to be thrown.');
        } catch (InvalidSeedConfig $exception) {
            self::assertStringContainsString('quote', strtolower($exception->getMessage()));
            self::assertStringContainsString('administrator.name', $exception->getMessage());
        }
    }

    public function testUnquotedNullLikeAllowlistEntrySuggestsQuoting(): void
    {
        $path = $this->writeIni(<<<INI
            [administrator]
            name = "Jane Doe"
            email = jane@example.com

            [access]
            allowlist[] = none
            INI);

        try {
            (new SeedConfigReader($path))->read();
            self::fail('Expected InvalidSeedConfig to be thrown.');
        } catch (InvalidSeedConfig $exception) {
            self::assertStringContainsString('quote', strtolower($exception->getMessage()));
        }
    }

    private const ADMINISTRATOR = <<<INI
        [administrator]
        name = "Jane Doe"
        email = "jane@example.com"

        INI;

    public function testWithoutAnSmtpSectionSmtpIsNull(): void
    {
        $seedConfig = (new SeedConfigReader($this->writeIni(self::ADMINISTRATOR)))->read();

        self::assertNotNull($seedConfig);
        self::assertNull($seedConfig->smtp);
    }

    public function testReadsTheSmtpSectionAsText(): void
    {
        $path = $this->writeIni(self::ADMINISTRATOR . <<<INI
            [smtp]
            host = "smtp.gmail.com"
            port = "587"
            username = "alerts@example.com"
            password = "abcd efgh ijkl mnop"
            from = "alerts@example.com"
            INI);

        $smtp = (new SeedConfigReader($path))->read()?->smtp;

        self::assertNotNull($smtp);
        self::assertSame('smtp.gmail.com', $smtp->host);
        self::assertSame('587', $smtp->port);
        self::assertSame('alerts@example.com', $smtp->username);
        self::assertSame('abcd efgh ijkl mnop', $smtp->password);
        self::assertSame('alerts@example.com', $smtp->from);
        self::assertStringNotContainsString('abcd', print_r($smtp, true));
    }

    public function testMissingSmtpKeysAreEmptyAndAnUnquotedPortIsAccepted(): void
    {
        $path = $this->writeIni(self::ADMINISTRATOR . <<<INI
            [smtp]
            host = "localhost"
            port = 1025
            INI);

        $smtp = (new SeedConfigReader($path))->read()?->smtp;

        self::assertNotNull($smtp);
        self::assertSame('1025', $smtp->port);
        self::assertSame('', $smtp->username);
        self::assertSame('', $smtp->password);
        self::assertSame('', $smtp->from);
    }

    public function testAnUnquotedPasswordIsRefusedWithoutRepeatingIt(): void
    {
        $path = $this->writeIni(self::ADMINISTRATOR . <<<INI
            [smtp]
            host = "smtp.gmail.com"
            password = 98765432
            INI);

        try {
            (new SeedConfigReader($path))->read();
            self::fail('Expected InvalidSeedConfig to be thrown.');
        } catch (InvalidSeedConfig $exception) {
            self::assertStringContainsString('smtp.password', $exception->getMessage());
            self::assertStringNotContainsString('98765432', $exception->getMessage());
        }
    }

    public function testAnUnquotedSmtpValueSuggestsQuoting(): void
    {
        $path = $this->writeIni(self::ADMINISTRATOR . <<<INI
            [smtp]
            host = none
            INI);

        try {
            (new SeedConfigReader($path))->read();
            self::fail('Expected InvalidSeedConfig to be thrown.');
        } catch (InvalidSeedConfig $exception) {
            self::assertStringContainsString('smtp.host', $exception->getMessage());
            self::assertStringContainsString('quote', strtolower($exception->getMessage()));
        }
    }

    public function testTheEnvironmentOverridesThePath(): void
    {
        $previous = getenv('MAGUARI_SEED_FILE');

        try {
            putenv('MAGUARI_SEED_FILE=' . $this->tempDir . '/seed.ini');
            self::assertSame($this->tempDir . '/seed.ini', SeedConfigReader::fromEnvironment()->path());
            putenv('MAGUARI_SEED_FILE');
            self::assertSame('/etc/maguari/seed.ini', SeedConfigReader::fromEnvironment()->path());
        } finally {
            putenv($previous === false ? 'MAGUARI_SEED_FILE' : 'MAGUARI_SEED_FILE=' . $previous);
        }
    }
}
