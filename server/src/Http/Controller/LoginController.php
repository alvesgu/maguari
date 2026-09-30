<?php

declare(strict_types=1);

namespace Maguari\Server\Http\Controller;

use Maguari\Server\Access\AccessApi;
use Maguari\Server\Http\RequestIp;
use Maguari\Server\Http\Session;
use Maguari\Server\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Local password login (design section 11.1).
 */
final class LoginController
{
    public function __construct(
        private readonly AccessApi $access,
        private readonly View $view,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->access->isSetupComplete()) {
            return $this->setupNotComplete($request, $response);
        }

        $id = $this->session($request)->administratorId();

        if ($id !== null && $this->access->administrator($id) !== null) {
            return $response->withStatus(303)->withHeader('Location', '/admin');
        }

        return $this->form($request, $response, '', null);
    }

    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->access->isSetupComplete()) {
            return $this->setupNotComplete($request, $response);
        }

        $body = (array) $request->getParsedBody();
        $email = FormInput::string($body, 'email');
        $administrator = $this->access->authenticate($email, FormInput::string($body, 'password'));

        if ($administrator === null) {
            $this->access->recordFailedAttempt(RequestIp::of($request));

            return $this->form($request, $response, $email, 'Incorrect email or password.', 422);
        }

        $this->session($request)->signIn($administrator->id);

        return $response->withStatus(303)->withHeader('Location', '/admin');
    }

    private function session(ServerRequestInterface $request): Session
    {
        $session = $request->getAttribute(Session::class);
        assert($session instanceof Session);

        return $session;
    }

    private function form(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $email,
        ?string $error,
        int $status = 200,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'login', [
            'title' => 'Sign in',
            'email' => $email,
            'error' => $error,
        ], $status);
    }

    private function setupNotComplete(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($request, $response, 'message', [
            'title' => 'Setup not complete',
            'message' => 'Maguari has no administrator yet. Run maguari-server issue-setup-token on the server and open the link it prints.',
        ]);
    }
}
