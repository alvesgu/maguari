<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Support;

/**
 * A certificate authority and server certificates made at test time, so no
 * key is ever committed. EC keys, because RSA key generation is slow.
 */
final class TestCertificateAuthority
{
    private readonly \OpenSSLAsymmetricKey $key;
    private readonly \OpenSSLCertificate $certificate;

    /**
     * @param string $directory where the OpenSSL configuration is written
     */
    public function __construct(private readonly string $directory)
    {
        $this->key = self::newKey();
        $this->certificate = $this->sign('Maguari Test CA', [], 30, null, $this->key, true);
    }

    /** The CA certificate, PEM, for the client to trust. */
    public function certificatePem(): string
    {
        openssl_x509_export($this->certificate, $pem);

        return $pem;
    }

    /**
     * A server certificate and its key, PEM, as a server's local_cert.
     *
     * @param list<string> $domains
     * @param int $days negative for one that has already expired
     * @param bool $selfSigned signed by its own key instead of this CA
     * @return array{pem: string, expires_at: int}
     */
    public function serverCertificate(array $domains, int $days, bool $selfSigned = false): array
    {
        $key = self::newKey();
        $certificate = $this->sign($domains[0], $domains, $days, $selfSigned ? null : $this->certificate, $selfSigned ? $key : $this->key, false, $key);
        openssl_x509_export($certificate, $pem);
        openssl_pkey_export($key, $keyPem);

        return ['pem' => $pem . $keyPem, 'expires_at' => openssl_x509_parse($certificate)['validTo_time_t']];
    }

    private static function newKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        return $key ?: throw new \RuntimeException('Could not create a test key.');
    }

    /**
     * @param list<string> $domains
     */
    private function sign(
        string $commonName,
        array $domains,
        int $days,
        ?\OpenSSLCertificate $issuer,
        \OpenSSLAsymmetricKey $signingKey,
        bool $authority,
        ?\OpenSSLAsymmetricKey $subjectKey = null,
    ): \OpenSSLCertificate {
        $config = $this->directory . '/openssl-' . bin2hex(random_bytes(4)) . '.cnf';
        file_put_contents($config, "[req]\ndefault_bits = 2048\ndistinguished_name = dn\n[dn]\n[extensions]\n"
            . ($authority ? "basicConstraints = critical, CA:TRUE\nkeyUsage = critical, keyCertSign\n" : "basicConstraints = CA:FALSE\nextendedKeyUsage = serverAuth\n")
            . ($domains === [] ? '' : 'subjectAltName = ' . implode(',', array_map(static fn (string $domain): string => 'DNS:' . $domain, $domains)) . "\n"));
        $options = ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => 'extensions'];
        // Passed by reference, so it must be a variable.
        $requestKey = $subjectKey ?? $signingKey;
        $request = openssl_csr_new(['commonName' => $commonName], $requestKey, $options);
        $certificate = $request instanceof \OpenSSLCertificateSigningRequest
            ? openssl_csr_sign($request, $issuer, $signingKey, $days, $options, random_int(1, PHP_INT_MAX))
            : false;
        unlink($config);

        return $certificate ?: throw new \RuntimeException('Could not sign a test certificate: ' . (string) openssl_error_string());
    }
}
