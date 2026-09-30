<?php

declare(strict_types=1);

namespace Maguari\Server\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Temporary: rejects every request until the real session/CSRF (/admin) and HMAC
 * (/api/client) middleware exist. Delete it when they land; do not extend it.
 */
final class FailClosedMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(401);
        $response->getBody()->write('Not available yet');

        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}
