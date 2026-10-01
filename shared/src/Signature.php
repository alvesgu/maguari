<?php

declare(strict_types=1);

namespace Maguari\Shared;

/**
 * Per-request HMAC signing (design section 5.5), used by both the client and
 * the server so they cannot drift apart:
 *
 *   canonical = METHOD \n path \n timestamp \n nonce \n hex(sha256(body))
 *   signature = hex(hmac_sha256(secret, canonical))
 *
 * The path has no query string: signed routes take none, because it would not
 * be covered by the signature.
 */
final class Signature
{
    public static function canonical(string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        return implode("\n", [strtoupper($method), $path, $timestamp, $nonce, hash('sha256', $body)]);
    }

    /**
     * @return string 64 lowercase hex characters
     */
    public static function sign(
        #[\SensitiveParameter]
        string $secret,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $body,
    ): string {
        return hash_hmac('sha256', self::canonical($method, $path, $timestamp, $nonce, $body), $secret);
    }

    /**
     * Compares in constant time.
     */
    public static function verify(
        #[\SensitiveParameter]
        string $secret,
        string $signature,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $body,
    ): bool {
        return hash_equals(self::sign($secret, $method, $path, $timestamp, $nonce, $body), $signature);
    }

    /**
     * @return string 16 random bytes as 32 lowercase hex characters, new for every request
     */
    public static function newNonce(): string
    {
        return bin2hex(random_bytes(16));
    }
}
