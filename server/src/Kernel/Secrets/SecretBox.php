<?php

declare(strict_types=1);

namespace Maguari\Server\Kernel\Secrets;

/**
 * Encrypts secrets that must be kept in their original form (design section
 * 9.3) with sodium_crypto_secretbox. The stored form is the 24-byte nonce
 * followed by the ciphertext.
 */
final class SecretBox
{
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $key,
    ) {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('A secret key is exactly 32 bytes.');
        }
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return $nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key);
    }

    /**
     * @throws SecretDecryptionFailed
     */
    public function decrypt(string $stored): string
    {
        $nonce = substr($stored, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($stored, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        if (strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || $ciphertext === '') {
            throw new SecretDecryptionFailed('The stored secret is too short.');
        }

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

        if ($plaintext === false) {
            throw new SecretDecryptionFailed('The stored secret could not be decrypted.');
        }

        return $plaintext;
    }
}
