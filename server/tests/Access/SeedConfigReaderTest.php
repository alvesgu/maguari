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
}
