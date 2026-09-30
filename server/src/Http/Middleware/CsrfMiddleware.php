<?php

declare(strict_types=1);

namespace Maguari\Server\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Csrf\Guard;

/**
 * slim/csrf keeps its tokens in $_SESSION, which only exists once
 * SessionMiddleware has started the session, so the Guard is built per request.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $storage = null;
        $guard = new Guard(
            $this->responseFactory,
            'csrf',
            $storage,
            function (): ResponseInterface {
                $response = $this->responseFactory->createResponse(400);
                $response->getBody()->write('The form has expired or is invalid. Go back, reload the page and try again.');

                return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
            },
            200,
            16,
            true,
        );

        return $guard->process($request, $handler);
    }
}
