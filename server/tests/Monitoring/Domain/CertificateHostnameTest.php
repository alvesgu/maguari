<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Monitoring\Domain;

use Maguari\Server\Monitoring\Domain\CertificateHostname;
use Maguari\Server\Monitoring\Exception\InvalidCertificateHostname;
use PHPUnit\Framework\TestCase;

final class CertificateHostnameTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function accepted(): array
    {
        return [
            'hostname' => ['www.example.com', 'www.example.com'],
            'trimmed and lowercased' => ['  WWW.Example.COM ', 'www.example.com'],
            'domain' => ['example.com', 'example.com'],
            'digits and hyphens' => ['web-2.a1.example', 'web-2.a1.example'],
            'punycode' => ['xn--bcher-kva.example', 'xn--bcher-kva.example'],
            'longest label' => [str_repeat('a', 63) . '.example', str_repeat('a', 63) . '.example'],
            'longest' => [str_repeat('a', 63) . '.' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 61), str_repeat('a', 63) . '.' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 61)],
        ];
    }

    /**
     * @dataProvider accepted
     */
    public function testAccepts(string $input, string $stored): void
    {
        $this->assertSame($stored, CertificateHostname::normalize($input));
        $this->assertTrue(CertificateHostname::isValid($stored));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refused(): array
    {
        $invalid = 'That is not a valid hostname: use letters, digits, hyphens and dots, for example www.example.com.';
        $onlyTheHostname = 'Enter only the hostname, for example www.example.com, without https://, a port or a path.';

        return [
            'empty' => ['  ', 'Enter a hostname.'],
            'non-ASCII' => ['bücher.example', 'Enter internationalized names in their xn-- form, as in the certificate.'],
            'a URL' => ['https://www.example.com/', $onlyTheHostname],
            'a port' => ['www.example.com:8443', $onlyTheHostname],
            'a path' => ['www.example.com/admin', $onlyTheHostname],
            'a space inside' => ['www example.com', $onlyTheHostname],
            'a user' => ['me@example.com', $onlyTheHostname],
            'IPv6' => ['::1', $onlyTheHostname],
            'wildcard' => ['*.example.com', 'Enter a hostname the certificate covers, for example www.example.com, not a wildcard.'],
            'one label' => ['localhost', 'Enter a full hostname with its domain, for example www.example.com.'],
            'IPv4' => ['192.168.0.1', 'Enter a hostname, not an IP address.'],
            'empty label' => ['www..example.com', $invalid],
            'trailing dot' => ['www.example.com.', $invalid],
            'leading hyphen' => ['-www.example.com', $invalid],
            'trailing hyphen' => ['www-.example.com', $invalid],
            'underscore' => ['my_site.example.com', $invalid],
            'label too long' => [str_repeat('a', 64) . '.example', $invalid],
            'too long' => [str_repeat('a', 63) . '.' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 62), 'A hostname has at most 253 characters.'],
        ];
    }

    /**
     * @dataProvider refused
     */
    public function testRefusesWithASentence(string $input, string $sentence): void
    {
        try {
            CertificateHostname::normalize($input);
            $this->fail('Accepted ' . $input);
        } catch (InvalidCertificateHostname $invalid) {
            $this->assertSame($sentence, $invalid->getMessage());
        }

        $this->assertFalse(CertificateHostname::isValid($input));
    }

    public function testSuggestionsMustAlreadyBeNormalized(): void
    {
        $this->assertFalse(CertificateHostname::isValid('WWW.example.com'));
    }
}
