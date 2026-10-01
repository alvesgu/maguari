<?php

declare(strict_types=1);

namespace Maguari\Client\Tests;

use Maguari\Client\ClientFailure;
use Maguari\Client\Credentials;
use Maguari\Client\CredentialsFile;
use Maguari\Client\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class CredentialsFileTest extends TestCase
{
    private TemporaryDirectory $directory;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    private static function credentials(string $secret = ''): Credentials
    {
        return new Credentials('https://maguari.example.com', str_repeat('a', 32), $secret === '' ? random_bytes(32) : $secret);
    }

    public function testSavesWithMode0600InADirectoryWithMode0700(): void
    {
        $file = new CredentialsFile($this->directory->path . '/state');
        $credentials = self::credentials();

        $file->save($credentials);

        $this->assertSame(0700, fileperms($this->directory->path . '/state') & 0777);
        $this->assertSame(0600, fileperms($file->path()) & 0777);
        $this->assertEquals($credentials, $file->load());
        $this->assertSame(['credentials.json'], array_values(array_diff(scandir($this->directory->path . '/state'), ['.', '..'])), 'No temporary file is left behind.');
    }

    public function testTheSecretIsStoredEncodedNotRaw(): void
    {
        $secret = random_bytes(32);
        $file = new CredentialsFile($this->directory->path);
        $file->save(self::credentials($secret));

        $this->assertStringNotContainsString($secret, (string) file_get_contents($file->path()));
    }

    public function testTheTemporaryFileIsPrivateEvenWithAPermissiveUmask(): void
    {
        $previous = umask(0000);

        try {
            (new CredentialsFile($this->directory->path . '/state'))->save(self::credentials());
        } finally {
            umask($previous);
        }

        $this->assertSame(0700, fileperms($this->directory->path . '/state') & 0777);
        $this->assertSame(0600, fileperms($this->directory->path . '/state/credentials.json') & 0777);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function unsafeModes(): array
    {
        return ['group readable' => [0640], 'world readable' => [0604], 'group writable' => [0620], 'all' => [0666]];
    }

    /**
     * @dataProvider unsafeModes
     */
    public function testRefusesAFileOthersCanAccess(int $mode): void
    {
        $file = new CredentialsFile($this->directory->path);
        $file->save(self::credentials());
        chmod($file->path(), $mode);

        $this->expectException(ClientFailure::class);
        $this->expectExceptionMessage('must be readable only by its owner. Fix it with: chmod 600 ');

        $file->load();
    }

    public function testMissingFileSaysToEnroll(): void
    {
        $this->expectException(ClientFailure::class);
        $this->expectExceptionMessage('This client is not enrolled');

        (new CredentialsFile($this->directory->path))->load();
    }

    public function testInvalidFile(): void
    {
        $file = new CredentialsFile($this->directory->path);
        file_put_contents($file->path(), '{"server_url": "http://maguari.example.com", "client_id": "x", "secret": "y"}');
        chmod($file->path(), 0600);

        $this->expectException(ClientFailure::class);
        $this->expectExceptionMessage('is not valid. Enroll the client again.');

        $file->load();
    }

    public function testAFailedSaveLeavesTheExistingFileUntouched(): void
    {
        $file = new CredentialsFile($this->directory->path);
        $original = self::credentials();
        $file->save($original);
        chmod($this->directory->path, 0500);

        try {
            $file->save(self::credentials());
            $this->fail('Expected ClientFailure.');
        } catch (ClientFailure) {
        } finally {
            chmod($this->directory->path, 0700);
        }

        $this->assertEquals($original, $file->load());
    }

    public function testDirectoryFromTheEnvironment(): void
    {
        putenv('MAGUARI_CLIENT_DIR');
        $this->assertSame('/var/lib/maguari-client/credentials.json', CredentialsFile::fromEnvironment()->path());

        putenv('MAGUARI_CLIENT_DIR=' . $this->directory->path);

        try {
            $this->assertSame($this->directory->path . '/credentials.json', CredentialsFile::fromEnvironment()->path());
        } finally {
            putenv('MAGUARI_CLIENT_DIR');
        }
    }
}
