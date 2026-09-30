<?php

declare(strict_types=1);

namespace Maguari\Server\Http\Middleware;

use Maguari\Server\Access\AccessApi;
use Maguari\Server\Access\LoginThrottle;
use Maguari\Server\Http\RequestIp;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rejects login and setup submissions from an IP with too many recent failures.
 * Controllers record the failures.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AccessApi $access,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->access->isThrottled(RequestIp::of($request))) {
            return $handler->handle($request);
        }

        $response = $this->responseFactory->createResponse(429);
        $response->getBody()->write('Too many failed attempts. Try again later.');

        return $response
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Retry-After', (string) LoginThrottle::WINDOW_SECONDS);
    }
}
