<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Kernel\Secrets;

use Maguari\Server\Kernel\Secrets\SecretBox;
use Maguari\Server\Kernel\Secrets\SecretDecryptionFailed;
use PHPUnit\Framework\TestCase;

final class SecretBoxTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $box = new SecretBox(random_bytes(32));
        $secret = random_bytes(32);

        $stored = $box->encrypt($secret);

        $this->assertSame($secret, $box->decrypt($stored));
        $this->assertStringNotContainsString($secret, $stored);
        $this->assertSame(24 + 32 + SODIUM_CRYPTO_SECRETBOX_MACBYTES, strlen($stored));
    }

    public function testEachEncryptionUsesANewNonce(): void
    {
        $box = new SecretBox(random_bytes(32));

        $this->assertNotSame($box->encrypt('same'), $box->encrypt('same'));
    }

    public function testAnotherKeyCannotDecrypt(): void
    {
        $stored = (new SecretBox(random_bytes(32)))->encrypt('secret');

        $this->expectException(SecretDecryptionFailed::class);

        (new SecretBox(random_bytes(32)))->decrypt($stored);
    }

    public function testChangedCiphertextCannotBeDecrypted(): void
    {
        $box = new SecretBox(random_bytes(32));
        $stored = $box->encrypt('secret');
        $stored[30] = chr(ord($stored[30]) ^ 1);

        $this->expectException(SecretDecryptionFailed::class);

        $box->decrypt($stored);
    }

    public function testTooShortCannotBeDecrypted(): void
    {
        $this->expectException(SecretDecryptionFailed::class);

        (new SecretBox(random_bytes(32)))->decrypt(str_repeat("\0", 24));
    }

    public function testKeyMustBe32Bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SecretBox(random_bytes(31));
    }
}
