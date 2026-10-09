<?php

declare(strict_types=1);

namespace Maguari\Server\Http\Middleware;

use Maguari\Server\Access\AccessApi;
use Maguari\Server\Access\Administrator;
use Maguari\Server\Http\AdminApiError;
use Maguari\Server\Http\AdminApiResponse;
use Maguari\Server\Http\Session;
use Maguari\Server\Http\SurfaceErrorHandler;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Lets the request through only with a signed-in administrator, who is added to
 * the request as the Administrator::class attribute. Pages redirect to the
 * login page; under /admin/api/ the answer is 401 JSON instead, because a
 * script's fetch() would follow the redirect and get the login page.
 */
final class RequireAdministratorMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AccessApi $access,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $request->getAttribute(Session::class);
        $id = $session instanceof Session ? $session->administratorId() : null;
        $administrator = $id === null ? null : $this->access->administrator($id);

        if ($administrator === null && SurfaceErrorHandler::isAdminApi($request)) {
            return AdminApiResponse::error($this->responseFactory->createResponse(), AdminApiError::Unauthorized);
        }

        if ($administrator === null) {
            return $this->responseFactory->createResponse(303)->withHeader('Location', '/auth/login');
        }

        return $handler->handle($request->withAttribute(Administrator::class, $administrator));
    }
}
