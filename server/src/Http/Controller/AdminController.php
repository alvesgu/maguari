<?php

declare(strict_types=1);

namespace Maguari\Server\Http\Controller;

use Maguari\Server\Access\Administrator;
use Maguari\Server\Http\Session;
use Maguari\Server\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AdminController
{
    public function __construct(
        private readonly View $view,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($request, $response, 'admin', [
            'title' => 'Dashboard',
            'administrator' => $request->getAttribute(Administrator::class),
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
