<?php

declare(strict_types=1);

namespace Maguari\Server\Http;

use Maguari\Server\Access\AccessApi;
use Maguari\Server\Clients\ClientsApi;
use Maguari\Server\Fleet\FleetApi;
use Maguari\Server\Fleet\Gcp\AccessTokenSourceFactory;
use Maguari\Server\Http\Controller\AdminController;
use Maguari\Server\Http\Controller\EnrollController;
use Maguari\Server\Http\Controller\HeartbeatController;
use Maguari\Server\Http\Controller\LoginController;
use Maguari\Server\Http\Controller\ProjectsController;
use Maguari\Server\Http\Controller\SetupController;
use Maguari\Server\Http\Middleware\ClientSignatureMiddleware;
use Maguari\Server\Http\Middleware\CsrfMiddleware;
use Maguari\Server\Http\Middleware\RateLimitMiddleware;
use Maguari\Server\Http\Middleware\RequireAdministratorMiddleware;
use Maguari\Server\Http\Middleware\SecurityHeadersMiddleware;
use Maguari\Server\Http\Middleware\SessionMiddleware;
use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Kernel\Database\Migrator;
use Maguari\Server\Kernel\HttpClient\StreamHttpClient;
use Maguari\Server\Kernel\Secrets\SecretBox;
use Maguari\Server\Kernel\Secrets\SecretKeyFile;
use Maguari\Server\Kernel\Secrets\SecretKeyUnavailable;
use Maguari\Server\Kernel\SystemClock;
use Maguari\Shared\ErrorCode;
use Psr\Http\Message\ResponseInterface;
use Slim\App as SlimApp;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Slim\Handlers\ErrorHandler;
use Slim\Routing\RouteCollectorProxy;

final class App
{
    public const NOT_SET_UP_MESSAGE = 'Maguari is not set up yet. Run maguari-server issue-setup-token on the server.';
    public const NO_SECRET_KEY_MESSAGE = 'Maguari is not set up yet: the secret key file is missing or unusable. '
        . 'Run maguari-server create-secret-key on the server.';
    public const STARTUP_FAILED_MESSAGE = 'Maguari is unavailable: it could not start. The details are in the server\'s error log.';

    /**
     * Builds the app from the database at MAGUARI_DATABASE (or the default
     * path) and the secret key file at MAGUARI_SECRET_KEY_FILE (or the default
     * path). Sessions are kept in a sessions/ directory next to the database.
     * Google Cloud credentials come from MAGUARI_GCP_CREDENTIALS (the metadata
     * server unless it says otherwise).
     *
     * Never throws: when anything needed at startup fails (for example a
     * database that cannot be opened), the details go to the error log and
     * every surface answers 503, as while Maguari is not set up.
     */
    public static function fromEnvironment(): SlimApp
    {
        try {
            return self::buildFromEnvironment();
        } catch (\Throwable $exception) {
            error_log(sprintf('Maguari could not start: %s: %s', $exception::class, $exception->getMessage()));

            return self::create(null, null, null, '', new SystemClock(), notReadyMessage: self::STARTUP_FAILED_MESSAGE);
        }
    }

    private static function buildFromEnvironment(): SlimApp
    {
        $database = Database::fromEnvironment();
        $clock = new SystemClock();
        $http = new StreamHttpClient();
        $tokens = AccessTokenSourceFactory::fromEnvironment($http, $clock);
        $sessionPath = dirname($database->path()) . '/sessions';

        if (!(new Migrator($database))->isUpToDate()) {
            return self::create(null, null, null, $sessionPath, $clock);
        }

        try {
            $secretBox = new SecretBox(SecretKeyFile::fromEnvironment()->read());
        } catch (SecretKeyUnavailable $unavailable) {
            // The reason (which names the path) goes to the log, not the page.
            error_log('Maguari: ' . $unavailable->getMessage());

            return self::create(null, null, null, $sessionPath, $clock, notReadyMessage: self::NO_SECRET_KEY_MESSAGE);
        }

        return self::create(
            new AccessApi($database, $clock),
            new FleetApi($database, $clock, $tokens, $http),
            new ClientsApi($database, $clock, $secretBox),
            $sessionPath,
            $clock,
        );
    }

    /**
     * @param AccessApi|null $access null while the database is missing or not
     *                               fully migrated, or the secret key file is
     *                               unusable: every surface then returns 503
     * @param FleetApi|null $fleet null in the same case as $access
     * @param ClientsApi|null $clients null in the same case as $access
     * @param bool $logErrors log uncaught errors to PHP's error log. Tests pass false.
     * @param string $notReadyMessage what /admin and /auth say while not ready
     */
    public static function create(
        ?AccessApi $access,
        ?FleetApi $fleet,
        ?ClientsApi $clients,
        string $sessionPath,
        Clock $clock = new SystemClock(),
        bool $logErrors = true,
        string $notReadyMessage = self::NOT_SET_UP_MESSAGE,
    ): SlimApp {
        $app = AppFactory::create();
        $responseFactory = $app->getResponseFactory();

        // Slim runs middleware last-added first, so security headers wrap the
        // error handler and are also set on 404 and 500 responses.
        $app->addRoutingMiddleware();
        // Error details are never shown in responses, in any environment. They
        // go to the log only, so exception messages must never contain secrets.
        $errorMiddleware = $app->addErrorMiddleware(false, $logErrors, $logErrors);
        // Slim's own HTML error page links back with an inline onclick, which
        // the Content-Security-Policy blocks. Ours is registered for
        // Accept: text/html and as the default, which Slim uses when the Accept
        // header names no type it knows.
        $errorPage = new ErrorPageRenderer(new View());
        $htmlErrors = new ErrorHandler($app->getCallableResolver(), $responseFactory);
        $htmlErrors->registerErrorRenderer('text/html', $errorPage);
        $htmlErrors->setDefaultErrorRenderer('text/html', $errorPage);
        // Clients get JSON whatever their Accept header says.
        $clientApiErrors = new ErrorHandler($app->getCallableResolver(), $responseFactory);
        $clientApiErrors->forceContentType('application/json');
        $clientApiErrors->registerErrorRenderer('application/json', new ClientApiErrorRenderer());
        $errors = new SurfaceErrorHandler($htmlErrors, $clientApiErrors);
        $errorMiddleware->setDefaultErrorHandler($errors);
        // 404 and 405 are routine (scanners, typos), not errors worth logging.
        $errorMiddleware->setErrorHandler(
            [HttpNotFoundException::class, HttpMethodNotAllowedException::class],
            fn ($request, \Throwable $exception): ResponseInterface => $errors($request, $exception, false, false, false),
        );
        $app->add(new SecurityHeadersMiddleware());

        if ($access === null || $fleet === null || $clients === null) {
            $app->any('/{surface:admin|auth}[/{rest:.*}]', function ($request, ResponseInterface $response) use ($notReadyMessage): ResponseInterface {
                $response->getBody()->write($notReadyMessage);

                return $response->withStatus(503)->withHeader('Content-Type', 'text/plain; charset=utf-8');
            });
            $app->any('/api/client[/{rest:.*}]', fn ($request, ResponseInterface $response): ResponseInterface => ClientApiResponse::error($response, ErrorCode::Unavailable));
        } else {
            $session = new SessionMiddleware($sessionPath, $clock);
            $csrf = new CsrfMiddleware($responseFactory);
            $rateLimit = new RateLimitMiddleware($access, $responseFactory);
            $view = new View();
            $adminController = new AdminController($fleet, $clients, $view);
            $projectsController = new ProjectsController($fleet, $clients, $access, $view);
            $enrollController = new EnrollController($clients);
            $heartbeatController = new HeartbeatController($clients);
            $signature = new ClientSignatureMiddleware($clients, $responseFactory);
            $setupController = new SetupController($access, $view);
            $loginController = new LoginController($access, $view);

            $app->group('/admin', function (RouteCollectorProxy $group) use ($adminController, $projectsController): void {
                $group->get('', [$adminController, 'show']);
                $group->post('/logout', [$adminController, 'logout']);
                $group->get('/projects', [$projectsController, 'show']);
                $group->post('/projects', [$projectsController, 'add']);
                $group->get('/projects/{id:[0-9]+}', [$projectsController, 'instances']);
                $group->post('/projects/{id:[0-9]+}/instances', [$projectsController, 'enroll']);
            })->add(new RequireAdministratorMiddleware($access, $responseFactory))->add($csrf)->add($session);

            // CSRF protects the forms under /auth. The future OAuth callback is
            // protected by its state parameter instead (design section 10.2).
            $app->group('/auth', function (RouteCollectorProxy $group) use ($setupController, $loginController, $rateLimit): void {
                $group->get('/setup', [$setupController, 'show']);
                $group->post('/setup', [$setupController, 'submit'])->add($rateLimit);
                $group->get('/login', [$loginController, 'show']);
                $group->post('/login', [$loginController, 'submit'])->add($rateLimit);
            })->add($csrf)->add($session);

            // Enrollment is not signed: the client has no secret yet, and the
            // one-time token is the credential. Every other client route is in
            // the signed group, so new routes inherit its protection.
            $app->group('/api/client', function (RouteCollectorProxy $group) use ($enrollController, $heartbeatController, $signature): void {
                $group->post('/enroll', [$enrollController, 'enroll']);
                $group->group('', function (RouteCollectorProxy $signed) use ($heartbeatController): void {
                    $signed->post('/heartbeat', [$heartbeatController, 'receive']);
                })->add($signature);
            });
        }

        return $app;
    }
}
