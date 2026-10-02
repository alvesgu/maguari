<?php

declare(strict_types=1);

namespace Maguari\Client\Tests\Support;

/**
 * Self-signed certificates made at test time, so no key or certificate is
 * ever committed. EC keys, because RSA key generation is slow.
 */
final class TestCertificates
{
    /**
     * @param list<string> $domains subjectAltName DNS names; none leaves only the CN
     * @return array{pem: string, expires_at: int}
     */
    public static function create(string $commonName, array $domains, int $days): array
    {
        $directory = new TemporaryDirectory();

        try {
            $config = $directory->path . '/openssl.cnf';
            file_put_contents($config, "[req]\ndefault_bits = 2048\ndistinguished_name = dn\n[dn]\n[extensions]\n"
                . ($domains === [] ? '' : 'subjectAltName = ' . implode(',', array_map(static fn (string $domain): string => 'DNS:' . $domain, $domains)) . "\n")
                . "basicConstraints = CA:FALSE\n");
            $options = ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => 'extensions'];
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'] + $options);
            $request = $key === false ? false : openssl_csr_new(['commonName' => $commonName], $key, $options);
            $certificate = $request === false || $request === true ? false : openssl_csr_sign($request, null, $key, $days, $options, random_int(1, PHP_INT_MAX));

            if ($certificate === false || !openssl_x509_export($certificate, $pem)) {
                throw new \RuntimeException('Could not create a test certificate: ' . (string) openssl_error_string());
            }

            $parsed = openssl_x509_parse($pem);

            return ['pem' => $pem, 'expires_at' => $parsed['validTo_time_t']];
        } finally {
            $directory->remove();
        }
    }
}
