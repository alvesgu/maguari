<?php

declare(strict_types=1);

namespace Maguari\Server\Http;

use Maguari\Server\Access\AccessApi;
use Maguari\Server\Fleet\FleetApi;
use Maguari\Server\Fleet\Gcp\AccessTokenSourceFactory;
use Maguari\Server\Http\Controller\AdminController;
use Maguari\Server\Http\Controller\LoginController;
use Maguari\Server\Http\Controller\ProjectsController;
use Maguari\Server\Http\Controller\SetupController;
use Maguari\Server\Http\Middleware\CsrfMiddleware;
use Maguari\Server\Http\Middleware\FailClosedMiddleware;
use Maguari\Server\Http\Middleware\RateLimitMiddleware;
use Maguari\Server\Http\Middleware\RequireAdministratorMiddleware;
use Maguari\Server\Http\Middleware\SecurityHeadersMiddleware;
use Maguari\Server\Http\Middleware\SessionMiddleware;
use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Kernel\Database\Migrator;
use Maguari\Server\Kernel\HttpClient\StreamHttpClient;
use Maguari\Server\Kernel\SystemClock;
use Psr\Http\Message\ResponseInterface;
use Slim\App as SlimApp;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Slim\Handlers\ErrorHandler;
use Slim\Routing\RouteCollectorProxy;

final class App
{
    /**
     * Builds the app from the database at MAGUARI_DATABASE (or the default
     * path). Sessions are kept in a sessions/ directory next to the database.
     * Google Cloud credentials come from MAGUARI_GCP_CREDENTIALS (the metadata
     * server unless it says otherwise).
     */
    public static function fromEnvironment(): SlimApp
    {
        $database = Database::fromEnvironment();
        $clock = new SystemClock();
        $http = new StreamHttpClient();
        $tokens = AccessTokenSourceFactory::fromEnvironment($http, $clock);
        $ready = (new Migrator($database))->isUpToDate();

        return self::create(
            $ready ? new AccessApi($database, $clock) : null,
            $ready ? new FleetApi($database, $clock, $tokens, $http) : null,
            dirname($database->path()) . '/sessions',
            $clock,
        );
    }

    /**
     * @param AccessApi|null $access null while the database is missing or not
     *                               fully migrated: /admin and /auth then return 503
     * @param FleetApi|null $fleet null in the same case as $access
     * @param bool $logErrors log uncaught errors to PHP's error log. Tests pass false.
     */
    public static function create(
        ?AccessApi $access,
        ?FleetApi $fleet,
        string $sessionPath,
        Clock $clock = new SystemClock(),
        bool $logErrors = true,
    ): SlimApp {
        $app = AppFactory::create();
        $responseFactory = $app->getResponseFactory();

        // Slim runs middleware last-added first, so security headers wrap the
        // error handler and are also set on 404 and 500 responses.
        $app->addRoutingMiddleware();
        // Error details are never shown in responses, in any environment. They
        // go to the log only, so exception messages must never contain secrets.
        $errorMiddleware = $app->addErrorMiddleware(false, $logErrors, $logErrors);
        // 404 and 405 are routine (scanners, typos), not errors worth logging.
        $routineHandler = new ErrorHandler($app->getCallableResolver(), $responseFactory);
        $errorMiddleware->setErrorHandler(
            [HttpNotFoundException::class, HttpMethodNotAllowedException::class],
            fn ($request, \Throwable $exception): ResponseInterface => $routineHandler($request, $exception, false, false, false),
        );
        $app->add(new SecurityHeadersMiddleware());

        if ($access === null || $fleet === null) {
            $app->any('/{surface:admin|auth}[/{rest:.*}]', function ($request, ResponseInterface $response): ResponseInterface {
                $response->getBody()->write('Maguari is not set up yet. Run maguari-server issue-setup-token on the server.');

                return $response->withStatus(503)->withHeader('Content-Type', 'text/plain; charset=utf-8');
            });
        } else {
            $session = new SessionMiddleware($sessionPath, $clock);
            $csrf = new CsrfMiddleware($responseFactory);
            $rateLimit = new RateLimitMiddleware($access, $responseFactory);
            $view = new View();
            $adminController = new AdminController($view);
            $projectsController = new ProjectsController($fleet, $view);
            $setupController = new SetupController($access, $view);
            $loginController = new LoginController($access, $view);

            $app->group('/admin', function (RouteCollectorProxy $group) use ($adminController, $projectsController): void {
                $group->get('', [$adminController, 'show']);
                $group->post('/logout', [$adminController, 'logout']);
                $group->get('/projects', [$projectsController, 'show']);
                $group->post('/projects', [$projectsController, 'add']);
            })->add(new RequireAdministratorMiddleware($access, $responseFactory))->add($csrf)->add($session);

            // CSRF protects the forms under /auth. The future OAuth callback is
            // protected by its state parameter instead (design section 10.2).
            $app->group('/auth', function (RouteCollectorProxy $group) use ($setupController, $loginController, $rateLimit): void {
                $group->get('/setup', [$setupController, 'show']);
                $group->post('/setup', [$setupController, 'submit'])->add($rateLimit);
                $group->get('/login', [$loginController, 'show']);
                $group->post('/login', [$loginController, 'submit'])->add($rateLimit);
            })->add($csrf)->add($session);
        }

        $app->group('/api/client', function (RouteCollectorProxy $group): void {
            $group->post('/heartbeat', fn ($request, ResponseInterface $response): ResponseInterface => $response);
        })->add(new FailClosedMiddleware($responseFactory));

        return $app;
    }
}
