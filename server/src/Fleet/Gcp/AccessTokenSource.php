<?php

declare(strict_types=1);

namespace Maguari\Server\Fleet\Gcp;

/**
 * Where Maguari gets access tokens for Google Cloud APIs: the metadata server
 * in production, the developer's Application Default Credentials in development
 * and a fake in tests (design section 8).
 */
interface AccessTokenSource
{
    /**
     * @throws AccessTokenUnavailable
     */
    public function accessToken(): AccessToken;
}
