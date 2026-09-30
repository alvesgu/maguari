<?php

declare(strict_types=1);

namespace Maguari\Server\Http\Controller;

use Maguari\Server\Access\AccessApi;
use Maguari\Server\Access\Exception\InvalidSeedConfig;
use Maguari\Server\Access\Exception\InvalidSetupInput;
use Maguari\Server\Access\Exception\SetupNotAllowed;
use Maguari\Server\Http\RequestIp;
use Maguari\Server\Http\Session;
use Maguari\Server\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * The setup wizard (design section 11.2): creates the first administrator.
 */
final class SetupController
{
    public function __construct(
        private readonly AccessApi $access,
        private readonly View $view,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->access->isSetupComplete()) {
            throw new HttpNotFoundException($request);
        }

        $token = FormInput::string($request->getQueryParams(), 'token');

        if (!$this->access->isSetupTokenValid($token)) {
            return $this->invalidLink($request, $response);
        }

        // Prefilled from the seed config file, when it is present and valid.
        try {
            $seedConfig = $this->access->readSeedConfig();
        } catch (InvalidSeedConfig) {
            $seedConfig = null;
        }

        return $this->form($request, $response, $token, $seedConfig?->administratorName ?? '', $seedConfig?->administratorEmail ?? '', []);
    }

    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->access->isSetupComplete()) {
            throw new HttpNotFoundException($request);
        }

        $body = (array) $request->getParsedBody();
        $token = FormInput::string($body, 'token');
        $name = FormInput::string($body, 'name');
        $email = FormInput::string($body, 'email');

        try {
            $administrator = $this->access->completeSetup(
                $token,
                $name,
                $email,
                FormInput::string($body, 'password'),
                FormInput::string($body, 'password_confirmation'),
            );
        } catch (SetupNotAllowed) {
            $this->access->recordFailedAttempt(RequestIp::of($request));

            return $this->invalidLink($request, $response);
        } catch (InvalidSetupInput $exception) {
            return $this->form($request, $response, $token, $name, $email, $exception->errors, 422);
        }

        $session = $request->getAttribute(Session::class);
        assert($session instanceof Session);
        $session->signIn($administrator->id);

        return $response->withStatus(303)->withHeader('Location', '/admin');
    }

    /**
     * @param array<string, string> $errors
     */
    private function form(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $token,
        string $name,
        string $email,
        array $errors,
        int $status = 200,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'setup', [
            'title' => 'Set up',
            'token' => $token,
            'name' => $name,
            'email' => $email,
            'errors' => $errors,
        ], $status);
    }

    private function invalidLink(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($request, $response, 'message', [
            'title' => 'Setup link not valid',
            'message' => 'This setup link is invalid or expired. Run maguari-server issue-setup-token on the server to get a new one.',
        ], 403);
    }
}
