<?php

declare(strict_types=1);

namespace Maguari\Client\Tests;

use Maguari\Client\CertificateExpiry;
use Maguari\Client\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class CertificateExpiryTest extends TestCase
{
    private TemporaryDirectory $directory;
    private string $path;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
        $this->path = $this->directory->path . '/certificates.json';
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    /**
     * @param list<mixed> $certificates
     * @return list<array{metric: string, value: int}>
     */
    private function readings(array $certificates): array
    {
        file_put_contents($this->path, json_encode(['scanned_at' => 1_790_000_000, 'certificates' => $certificates]));

        return (new CertificateExpiry($this->path))->readings();
    }

    public function testOneReadingPerCertificateNamedByItsFirstDomain(): void
    {
        $this->assertSame([
            ['metric' => 'certificate_expires_at:example.com', 'value' => 1_797_000_000],
            ['metric' => 'certificate_expires_at:*.example.org', 'value' => 1_798_000_000],
        ], $this->readings([
            ['name' => 'example.com', 'domains' => ['example.com', 'www.example.com'], 'expires_at' => 1_797_000_000],
            ['name' => 'example.org', 'domains' => ['*.example.org', 'example.org'], 'expires_at' => 1_798_000_000],
        ]));
    }

    public function testASharedFirstDomainKeepsTheLatestExpiry(): void
    {
        $this->assertSame([['metric' => 'certificate_expires_at:example.com', 'value' => 1_799_000_000]], $this->readings([
            ['name' => 'example.com', 'domains' => ['example.com'], 'expires_at' => 1_791_000_000],
            ['name' => 'example.com-0001', 'domains' => ['example.com'], 'expires_at' => 1_799_000_000],
            ['name' => 'example.com-0002', 'domains' => ['Example.com'], 'expires_at' => 1_795_000_000],
        ]));
    }

    public function testDomainsAreLowercasedAndInvalidOnesSkipped(): void
    {
        $this->assertSame([
            ['metric' => 'certificate_expires_at:www.example.com', 'value' => 3],
            ['metric' => 'certificate_expires_at:123', 'value' => 4],
        ], $this->readings([
            ['domains' => ['WWW.Example.com'], 'expires_at' => 3],
            ['domains' => ['bücher.example'], 'expires_at' => 1],
            ['domains' => ['example.com.'], 'expires_at' => 1],
            ['domains' => ["example.com\nx"], 'expires_at' => 1],
            // A numeric domain becomes an integer array key on the way.
            ['domains' => ['123'], 'expires_at' => 4],
        ]));
    }

    public function testMalformedEntriesAreSkipped(): void
    {
        $this->assertSame([['metric' => 'certificate_expires_at:ok.example', 'value' => 5]], $this->readings([
            'not an object',
            ['domains' => [], 'expires_at' => 1],
            ['domains' => 'example.com', 'expires_at' => 1],
            ['domains' => [42], 'expires_at' => 1],
            ['domains' => ['example.com']],
            ['domains' => ['example.com'], 'expires_at' => '1'],
            ['domains' => ['example.com'], 'expires_at' => -1],
            ['domains' => ['example.com'], 'expires_at' => 1.5],
            ['domains' => ['ok.example'], 'expires_at' => 5],
        ]));
    }

    public function testAtMost20Domains(): void
    {
        $certificates = [];

        for ($i = 1; $i <= 21; $i++) {
            $certificates[] = ['domains' => ['site' . $i . '.example'], 'expires_at' => $i];
        }

        // A later certificate for a domain already counted still updates it.
        $certificates[] = ['domains' => ['site1.example'], 'expires_at' => 100];
        $readings = $this->readings($certificates);

        $this->assertCount(CertificateExpiry::MAX_CERTIFICATES, $readings);
        $this->assertSame(['metric' => 'certificate_expires_at:site1.example', 'value' => 100], $readings[0]);
        $this->assertSame('certificate_expires_at:site20.example', $readings[19]['metric']);
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function unusableFiles(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'not JSON' => ['{"certificates": ['],
            'not an object' => ['[1, 2]'],
            'no certificates' => ['{"scanned_at": 1}'],
            'certificates not a list' => ['{"certificates": "example.com"}'],
            'too deep' => ['{"certificates": [{"domains": [[[[[[[[["example.com"]]]]]]]]], "expires_at": 1}]}'],
        ];
    }

    /**
     * @dataProvider unusableFiles
     */
    public function testAnUnusableFileGivesNoReadings(?string $contents): void
    {
        if ($contents !== null) {
            file_put_contents($this->path, $contents);
        }

        $this->assertSame([], (new CertificateExpiry($this->path))->readings());
    }
}
