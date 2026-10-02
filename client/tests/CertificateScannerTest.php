<?php

declare(strict_types=1);

namespace Maguari\Client\Tests;

use Maguari\Client\CertificateScanner;
use Maguari\Client\ClientFailure;
use Maguari\Client\Tests\Support\FakeClock;
use Maguari\Client\Tests\Support\TemporaryDirectory;
use Maguari\Client\Tests\Support\TestCertificates;
use PHPUnit\Framework\TestCase;

final class CertificateScannerTest extends TestCase
{
    private TemporaryDirectory $directory;
    private string $letsencrypt;
    private string $output;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
        $this->letsencrypt = $this->directory->path . '/letsencrypt';
        $this->output = $this->directory->path . '/state/certificates.json';
        mkdir($this->letsencrypt . '/live', 0700, true);
        mkdir($this->directory->path . '/state', 0755);
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    private function scanner(): CertificateScanner
    {
        return new CertificateScanner(new FakeClock(), $this->letsencrypt, $this->output);
    }

    /**
     * Lays out a lineage as certbot does: cert.pem in live/ is a symlink into
     * archive/, next to the private key.
     *
     * @param list<string> $domains
     * @return int the certificate's expiry
     */
    private function lineage(string $name, string $commonName, array $domains, int $days = 90): int
    {
        $certificate = TestCertificates::create($commonName, $domains, $days);
        $archive = $this->letsencrypt . '/archive/' . $name;
        $live = $this->letsencrypt . '/live/' . $name;
        mkdir($archive, 0700, true);
        mkdir($live, 0700);
        file_put_contents($archive . '/cert1.pem', $certificate['pem']);
        symlink('../../archive/' . $name . '/cert1.pem', $live . '/cert.pem');
        // Holds a certificate for another name, so reading it would show.
        file_put_contents($live . '/privkey.pem', TestCertificates::create('privkey.example', ['privkey.example'], 90)['pem']);

        return $certificate['expires_at'];
    }

    public function testReadsEachLineagesCertificate(): void
    {
        $first = $this->lineage('example.com', 'example.com', ['example.com', 'www.example.com']);
        $second = $this->lineage('example.org', 'example.org', ['example.org'], 30);

        $this->assertSame([
            ['name' => 'example.com', 'domains' => ['example.com', 'www.example.com'], 'expires_at' => $first],
            ['name' => 'example.org', 'domains' => ['example.org'], 'expires_at' => $second],
        ], $this->scanner()->scan());
    }

    public function testTheCommonNameIsTheFallbackWithoutAlternativeNames(): void
    {
        $expiresAt = $this->lineage('old', 'old.example.com', []);

        $this->assertSame([['name' => 'old', 'domains' => ['old.example.com'], 'expires_at' => $expiresAt]], $this->scanner()->scan());
    }

    public function testSkipsWhatIsNotAReadableCertificate(): void
    {
        $live = $this->letsencrypt . '/live';
        file_put_contents($live . '/README', "This directory contains your keys and certificates.\n");
        mkdir($live . '/.hidden');
        file_put_contents($live . '/.hidden/cert.pem', TestCertificates::create('hidden.example', ['hidden.example'], 90)['pem']);
        mkdir($live . '/no-certificate');
        mkdir($live . '/garbage');
        file_put_contents($live . '/garbage/cert.pem', "-----BEGIN CERTIFICATE-----\naGVsbG8gd29ybGQ=\n-----END CERTIFICATE-----\n");
        mkdir($live . '/a-path');
        file_put_contents($live . '/a-path/cert.pem', 'file:///etc/hostname');
        mkdir($live . '/too-large');
        file_put_contents($live . '/too-large/cert.pem', TestCertificates::create('large.example', ['large.example'], 90)['pem'] . str_repeat("\n", CertificateScanner::MAX_FILE_BYTES));
        mkdir($live . '/directory/cert.pem', 0700, true);
        $expiresAt = $this->lineage('valid', 'valid.example', ['valid.example']);

        $this->assertSame([['name' => 'valid', 'domains' => ['valid.example'], 'expires_at' => $expiresAt]], $this->scanner()->scan());
    }

    public function testAtMost20InNameOrder(): void
    {
        $pem = TestCertificates::create('example.com', ['example.com'], 90)['pem'];

        for ($i = 21; $i >= 1; $i--) {
            mkdir($this->letsencrypt . '/live/site' . sprintf('%02d', $i));
            file_put_contents($this->letsencrypt . '/live/site' . sprintf('%02d', $i) . '/cert.pem', $pem);
        }

        $names = array_column($this->scanner()->scan(), 'name');

        $this->assertCount(CertificateScanner::MAX_CERTIFICATES, $names);
        $this->assertSame('site01', $names[0]);
        $this->assertSame('site20', $names[19]);
    }

    public function testNoCertbotMeansNoCertificates(): void
    {
        rmdir($this->letsencrypt . '/live');

        $this->assertSame([], $this->scanner()->scan());
    }

    public function testAnUnreadableLiveDirectoryFails(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('root can read any directory.');
        }

        chmod($this->letsencrypt . '/live', 0000);

        $this->expectException(ClientFailure::class);
        $this->expectExceptionMessage('Cannot read ' . $this->letsencrypt . '/live. Run the certificate scanner as root.');
        $this->scanner()->scan();
    }

    public function testWritesTheFileForEveryoneToRead(): void
    {
        $certificates = [['name' => 'example.com', 'domains' => ['example.com'], 'expires_at' => 1_797_000_000]];
        file_put_contents($this->output, 'old');

        $this->scanner()->write($certificates);

        $this->assertSame(['scanned_at' => 1_790_000_000, 'certificates' => $certificates], json_decode((string) file_get_contents($this->output), true));
        $this->assertSame(0644, fileperms($this->output) & 0777);
        $this->assertSame(['certificates.json'], array_values(array_diff(scandir(dirname($this->output)) ?: [], ['.', '..'])));
    }

    public function testWritesAnEmptyList(): void
    {
        $this->scanner()->write([]);

        $this->assertSame(['scanned_at' => 1_790_000_000, 'certificates' => []], json_decode((string) file_get_contents($this->output), true));
    }

    public function testAMissingOutputDirectoryFails(): void
    {
        $this->output = $this->directory->path . '/missing/certificates.json';

        $this->expectException(ClientFailure::class);
        $this->expectExceptionMessage('Cannot write ' . $this->output . ': the directory ' . $this->directory->path . '/missing does not exist.');
        $this->scanner()->write([]);
    }
}
