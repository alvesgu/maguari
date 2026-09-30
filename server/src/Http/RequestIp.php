<?php

declare(strict_types=1);

namespace Maguari\Server\Http;

use Psr\Http\Message\ServerRequestInterface;

final class RequestIp
{
    /**
     * REMOTE_ADDR is the real client IP because Cloudflare runs in DNS-only mode
     * (design section 11.4). Forwarding headers are never trusted.
     */
    public static function of(ServerRequestInterface $request): string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        return is_string($ip) ? $ip : '';
    }
}
