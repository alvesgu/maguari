<?php

declare(strict_types=1);

namespace Maguari\Server\Http\Controller;

use Maguari\Server\Access\AccessApi;
use Maguari\Server\Clients\ClientsApi;
use Maguari\Server\Clients\EnrollmentState;
use Maguari\Server\Fleet\Exception\InstanceNotFound;
use Maguari\Server\Fleet\Exception\InvalidInstanceName;
use Maguari\Server\Fleet\Exception\InvalidProjectId;
use Maguari\Server\Fleet\Exception\ProjectAlreadyAdded;
use Maguari\Server\Fleet\Exception\ProjectNotAccessible;
use Maguari\Server\Fleet\Exception\ProjectNotFound;
use Maguari\Server\Fleet\FleetApi;
use Maguari\Server\Fleet\Project;
use Maguari\Server\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Adding GCP projects, listing their instances and picking instances for
 * enrollment (design section 8.1 items 1 to 3).
 */
final class ProjectsController
{
    public function __construct(
        private readonly FleetApi $fleet,
        private readonly ClientsApi $clients,
        private readonly AccessApi $access,
        private readonly View $view,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->page($request, $response, '', null);
    }

    public function add(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $projectId = FormInput::string((array) $request->getParsedBody(), 'project_id');

        try {
            $this->fleet->addProject($projectId);
        } catch (InvalidProjectId | ProjectAlreadyAdded | ProjectNotAccessible $exception) {
            return $this->page($request, $response, $projectId, $exception->getMessage(), 422);
        }

        return $response->withStatus(303)->withHeader('Location', '/admin/projects');
    }

    /**
     * @param array{id: string} $args
     */
    public function instances(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $project = $this->project($request, $args);

        $instances = null;
        $error = null;

        try {
            $instances = $this->fleet->listInstances($project->id);
        } catch (ProjectNotAccessible $exception) {
            // The page exists, so a Google Cloud failure is not an HTTP error.
            $error = $exception->getMessage();
        }

        return $this->view->render($request, $response, 'project', [
            'title' => $project->gcpProjectId,
            'project' => $project,
            'instances' => $instances,
            'enrollmentStates' => $this->enrollmentStates($project),
            'error' => $error,
        ]);
    }

    /**
     * Picks the instance and issues its enrollment token. The page shows the
     * token once, so it is rendered here instead of after a redirect: only its
     * hash is stored (design section 5.6).
     *
     * @param array{id: string} $args
     */
    public function enroll(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $project = $this->project($request, $args);
        $form = (array) $request->getParsedBody();
        $data = ['title' => 'Enroll an instance', 'project' => $project, 'instance' => null, 'command' => null, 'expiresAt' => null];
        // From configuration, never from the request: the Host header is
        // chosen by whoever sends the request.
        $baseUrl = $this->access->baseUrl();

        if ($baseUrl === null) {
            return $this->view->render($request, $response, 'enroll', $data + [
                'error' => 'Maguari does not know its own address yet, so it cannot show the enroll command. On the server, run: '
                    . 'sudo -u maguari-server maguari-server set-base-url --base-url=https://maguari.example.com '
                    . '(with your server\'s domain).',
            ], 409);
        }

        try {
            $instance = $this->fleet->pickInstance($project->id, FormInput::string($form, 'zone'), FormInput::string($form, 'name'));
        } catch (InvalidInstanceName | InstanceNotFound | ProjectNotAccessible $exception) {
            return $this->view->render($request, $response, 'enroll', $data + ['error' => $exception->getMessage()], 422);
        }

        $issued = $this->clients->issueEnrollmentToken($instance->id);

        return $this->view->render($request, $response, 'enroll', [
            'title' => 'Enroll ' . $instance->name,
            'instance' => $instance,
            'command' => sprintf('maguari-client enroll --server=%s --token=%s', $baseUrl, $issued->token),
            'expiresAt' => $issued->expiresAt,
            'error' => null,
        ] + $data);
    }

    /**
     * @param array{id: string} $args
     */
    private function project(ServerRequestInterface $request, array $args): Project
    {
        try {
            return $this->fleet->project((int) $args['id']);
        } catch (ProjectNotFound) {
            throw new HttpNotFoundException($request);
        }
    }

    /**
     * @return array<string, EnrollmentState> keyed by "zone/name", for the project's picked instances
     */
    private function enrollmentStates(Project $project): array
    {
        $picked = $this->fleet->pickedInstances($project->id);
        $states = $this->clients->enrollmentStates(array_map(static fn ($instance): int => $instance->id, $picked));
        $byName = [];

        foreach ($picked as $instance) {
            $byName[$instance->zone . '/' . $instance->name] = $states[$instance->id];
        }

        return $byName;
    }

    private function page(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $projectId,
        ?string $error,
        int $status = 200,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'projects', [
            'title' => 'Projects',
            'projects' => $this->fleet->listProjects(),
            'projectId' => $projectId,
            'error' => $error,
        ], $status);
    }
}
