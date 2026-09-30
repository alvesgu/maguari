<?php

declare(strict_types=1);

namespace Maguari\Server\Fleet\Gcp;

use InvalidArgumentException;
use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\HttpClient\HttpClient;

/**
 * Chooses the token source from MAGUARI_GCP_CREDENTIALS: unset or "metadata"
 * for the metadata server (production), "application-default" for the
 * developer's gcloud login (development only).
 */
final class AccessTokenSourceFactory
{
    public const ENVIRONMENT_VARIABLE = 'MAGUARI_GCP_CREDENTIALS';

    public static function fromEnvironment(HttpClient $http, Clock $clock): AccessTokenSource
    {
        $setting = getenv(self::ENVIRONMENT_VARIABLE);
        $home = getenv('HOME');

        return self::create(is_string($setting) ? $setting : null, is_string($home) ? $home : null, $http, $clock);
    }

    /**
     * @throws InvalidArgumentException for an unknown setting, or application-default without HOME
     */
    public static function create(?string $setting, ?string $home, HttpClient $http, Clock $clock): AccessTokenSource
    {
        if ($setting === null || $setting === '' || $setting === 'metadata') {
            return new MetadataServerTokenSource($http, $clock);
        }

        if ($setting === 'application-default') {
            if ($home === null || $home === '') {
                throw new InvalidArgumentException(self::ENVIRONMENT_VARIABLE . '=application-default needs HOME to find the gcloud credentials.');
            }

            return new ApplicationDefaultCredentialsTokenSource($http, $clock, ApplicationDefaultCredentialsTokenSource::defaultPath($home));
        }

        throw new InvalidArgumentException(sprintf(
            '%s must be "metadata" or "application-default", not "%s".',
            self::ENVIRONMENT_VARIABLE,
            preg_replace('/[^\x20-\x7e]/', '?', substr($setting, 0, 40)),
        ));
    }
}
