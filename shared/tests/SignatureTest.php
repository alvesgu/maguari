<?php

declare(strict_types=1);

namespace Maguari\Shared\Tests;

use Maguari\Shared\Signature;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase
{
    private const NONCE = '00112233445566778899aabbccddeeff';
    private const BODY = '{"protocol_version":1}';

    private static function secret(): string
    {
        return implode('', array_map('chr', range(0, 31)));
    }

    /**
     * Vectors computed independently with Python's hmac module, so a change
     * to the canonical string or the encoding cannot pass unnoticed.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function vectors(): array
    {
        return [
            'with a body' => ['1790000000', self::BODY, 'a39bfe47d604a1f4f007cb3c1e8da6a73cccda3ba335ed1d2d3826c81ebe1004'],
            'empty body' => ['1790000000', '', '38291fc6d0c824645e810582b175d7da7b354b25c41d2adb0cd3b0e43caec674'],
            'another timestamp' => ['1790000001', self::BODY, 'eb063986457a23cba56518a6aa6ef44654d9ada3edd13746d31f5997afa4332d'],
        ];
    }

    /**
     * @dataProvider vectors
     */
    public function testMatchesTheVectors(string $timestamp, string $body, string $expected): void
    {
        $this->assertSame($expected, Signature::sign(self::secret(), 'POST', '/api/client/heartbeat', $timestamp, self::NONCE, $body));
        $this->assertTrue(Signature::verify(self::secret(), $expected, 'post', '/api/client/heartbeat', $timestamp, self::NONCE, $body));
    }

    public function testCanonicalString(): void
    {
        $this->assertSame(
            "POST\n/api/client/heartbeat\n1790000000\n" . self::NONCE . "\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
            Signature::canonical('post', '/api/client/heartbeat', '1790000000', self::NONCE, ''),
        );
    }

    public function testAnyChangeBreaksTheSignature(): void
    {
        $signature = Signature::sign(self::secret(), 'POST', '/api/client/heartbeat', '1790000000', self::NONCE, self::BODY);

        $this->assertFalse(Signature::verify(self::secret(), $signature, 'PUT', '/api/client/heartbeat', '1790000000', self::NONCE, self::BODY));
        $this->assertFalse(Signature::verify(self::secret(), $signature, 'POST', '/api/client/enroll', '1790000000', self::NONCE, self::BODY));
        $this->assertFalse(Signature::verify(self::secret(), $signature, 'POST', '/api/client/heartbeat', '1790000001', self::NONCE, self::BODY));
        $this->assertFalse(Signature::verify(self::secret(), $signature, 'POST', '/api/client/heartbeat', '1790000000', strrev(self::NONCE), self::BODY));
        $this->assertFalse(Signature::verify(self::secret(), $signature, 'POST', '/api/client/heartbeat', '1790000000', self::NONCE, self::BODY . ' '));
        $this->assertFalse(Signature::verify(str_repeat("\0", 32), $signature, 'POST', '/api/client/heartbeat', '1790000000', self::NONCE, self::BODY));
        $this->assertFalse(Signature::verify(self::secret(), strtoupper($signature), 'POST', '/api/client/heartbeat', '1790000000', self::NONCE, self::BODY));
    }

    public function testNoncesAreNewAndHex(): void
    {
        $nonce = Signature::newNonce();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $nonce);
        $this->assertNotSame($nonce, Signature::newNonce());
    }
}
