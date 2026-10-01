<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Kernel\Secrets;

use Maguari\Server\Kernel\Secrets\SecretKeyFile;
use Maguari\Server\Kernel\Secrets\SecretKeyUnavailable;
use PHPUnit\Framework\TestCase;

final class SecretKeyFileTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/maguari-key-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach ([$this->directory . '/etc/secret.key', $this->directory . '/secret.key'] as $file) {
            if (is_file($file)) {
                chmod($file, 0600);
                unlink($file);
            }
        }

        if (is_dir($this->directory . '/etc')) {
            rmdir($this->directory . '/etc');
        }

        rmdir($this->directory);
    }

    public function testCreatesA32ByteKeyWithMode0600AndItsDirectoryWithMode0700(): void
    {
        $keyFile = new SecretKeyFile($this->directory . '/etc/secret.key');

        $this->assertTrue($keyFile->create());

        $this->assertSame(0600, fileperms($keyFile->path()) & 0777);
        $this->assertSame(0700, fileperms($this->directory . '/etc') & 0777);
        $this->assertSame(32, strlen($keyFile->read()));
    }

    public function testNeverOverwritesAnExistingKey(): void
    {
        $keyFile = new SecretKeyFile($this->directory . '/secret.key');
        $keyFile->create();
        $key = $keyFile->read();

        $this->assertFalse($keyFile->create());

        $this->assertSame($key, $keyFile->read());
    }

    public function testMissingFile(): void
    {
        $this->expectException(SecretKeyUnavailable::class);
        $this->expectExceptionMessage($this->directory . '/secret.key does not exist');

        (new SecretKeyFile($this->directory . '/secret.key'))->read();
    }

    public function testWrongLength(): void
    {
        file_put_contents($this->directory . '/secret.key', random_bytes(31));
        chmod($this->directory . '/secret.key', 0600);

        $this->expectException(SecretKeyUnavailable::class);
        $this->expectExceptionMessage('is not a valid key');

        (new SecretKeyFile($this->directory . '/secret.key'))->read();
    }

    public function testRefusesAKeyReadableByOthers(): void
    {
        $keyFile = new SecretKeyFile($this->directory . '/secret.key');
        $keyFile->create();
        chmod($keyFile->path(), 0640);

        $this->expectException(SecretKeyUnavailable::class);
        $this->expectExceptionMessage('must be readable only by its owner (mode 0600)');

        $keyFile->read();
    }

    public function testDefaultPath(): void
    {
        putenv('MAGUARI_SECRET_KEY_FILE');

        $this->assertSame('/etc/maguari/secret.key', SecretKeyFile::fromEnvironment()->path());

        putenv('MAGUARI_SECRET_KEY_FILE=' . $this->directory . '/secret.key');

        try {
            $this->assertSame($this->directory . '/secret.key', SecretKeyFile::fromEnvironment()->path());
        } finally {
            putenv('MAGUARI_SECRET_KEY_FILE');
        }
    }
}
