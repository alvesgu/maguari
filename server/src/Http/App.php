<?php

declare(strict_types=1);

namespace Maguari\Server\Http;

use Maguari\Server\Http\Middleware\FailClosedMiddleware;
use Maguari\Server\Http\Middleware\SecurityHeadersMiddleware;
use Psr\Http\Message\ResponseInterface;
use Slim\App as SlimApp;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;

final class App
{
    public static function create(): SlimApp
    {
        $app = AppFactory::create();
        $responseFactory = $app->getResponseFactory();
        $failClosed = new FailClosedMiddleware($responseFactory);

        // Slim runs middleware last-added first, so security headers wrap the
        // error handler and are also set on 404 and 500 responses.
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, false);
        $app->add(new SecurityHeadersMiddleware());

        $app->group('/admin', function (RouteCollectorProxy $group): void {
            $group->get('', fn ($request, ResponseInterface $response): ResponseInterface => $response);
        })->add($failClosed);

        $app->group('/api/client', function (RouteCollectorProxy $group): void {
            $group->post('/heartbeat', fn ($request, ResponseInterface $response): ResponseInterface => $response);
        })->add($failClosed);

        $app->group('/auth', function (RouteCollectorProxy $group): void {
            $group->get('/login', function ($request, ResponseInterface $response): ResponseInterface {
                $response->getBody()->write('Not implemented yet');

                return $response->withStatus(501)->withHeader('Content-Type', 'text/plain; charset=utf-8');
            });
        });

        return $app;
    }
}
