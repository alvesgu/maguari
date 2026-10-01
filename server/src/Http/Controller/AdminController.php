<?php

declare(strict_types=1);

namespace Maguari\Server\Http\Controller;

use Maguari\Server\Access\Administrator;
use Maguari\Server\Clients\ClientsApi;
use Maguari\Server\Fleet\FleetApi;
use Maguari\Server\Fleet\Instance;
use Maguari\Server\Http\Session;
use Maguari\Server\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The dashboard: every picked instance with its heartbeat status (design
 * section 15 step 6). It reads only SQLite, so it works while Google Cloud's
 * API does not.
 */
final class AdminController
{
    public function __construct(
        private readonly FleetApi $fleet,
        private readonly ClientsApi $clients,
        private readonly View $view,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $instances = $this->fleet->pickedInstances();
        $ids = array_map(static fn (Instance $instance): int => $instance->id, $instances);

        return $this->view->render($request, $response, 'admin', [
            'title' => 'Dashboard',
            'administrator' => $request->getAttribute(Administrator::class),
            'instances' => $instances,
            'enrollmentStates' => $this->clients->enrollmentStates($ids),
            'heartbeats' => $this->clients->heartbeatStatuses($ids),
        ]);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $request->getAttribute(Session::class);
        assert($session instanceof Session);
        $session->destroy();

        return $response->withStatus(303)->withHeader('Location', '/auth/login');
    }
}
