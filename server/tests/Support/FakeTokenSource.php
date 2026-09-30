<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Support;

use Maguari\Server\Fleet\Gcp\AccessToken;
use Maguari\Server\Fleet\Gcp\AccessTokenSource;
use Maguari\Server\Fleet\Gcp\AccessTokenUnavailable;

/**
 * Hands out a fixed token, or fails with a configured message. No network.
 */
final class FakeTokenSource implements AccessTokenSource
{
    public const TOKEN = 'fake-access-token-7f3a9c';

    private ?string $failure = null;

    public int $calls = 0;

    public function failWith(string $message): void
    {
        $this->failure = $message;
    }

    public function accessToken(): AccessToken
    {
        $this->calls++;

        if ($this->failure !== null) {
            throw new AccessTokenUnavailable($this->failure);
        }

        return new AccessToken(self::TOKEN, 1_790_003_600);
    }
}
