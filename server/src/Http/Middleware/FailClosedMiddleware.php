<?php

declare(strict_types=1);

namespace Maguari\Server\Http\Middleware;

use Maguari\Server\Http\ClientApiResponse;
use Maguari\Shared\ErrorCode;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Temporary: rejects every request to the signed /api/client routes until the
 * real HMAC middleware exists (MVP step 6.3). Delete it when that lands; do not
 * extend it.
 */
final class FailClosedMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return ClientApiResponse::error($this->responseFactory->createResponse(), ErrorCode::Unauthorized);
    }
}
