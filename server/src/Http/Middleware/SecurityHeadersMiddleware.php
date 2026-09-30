<?php

declare(strict_types=1);

namespace Maguari\Server\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Security headers on every response (design section 11.3). Strict defaults for
 * now; loosen them only when the admin UI has real asset and script sources.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)
            ->withHeader('Content-Security-Policy', "default-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'")
            ->withHeader('Strict-Transport-Security', 'max-age=31536000')
            ->withHeader('X-Frame-Options', 'DENY');
    }
}
