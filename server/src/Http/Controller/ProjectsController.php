<?php

declare(strict_types=1);

namespace Maguari\Server\Http\Controller;

use Maguari\Server\Fleet\Exception\InvalidProjectId;
use Maguari\Server\Fleet\Exception\ProjectAlreadyAdded;
use Maguari\Server\Fleet\Exception\ProjectNotAccessible;
use Maguari\Server\Fleet\Exception\ProjectNotFound;
use Maguari\Server\Fleet\FleetApi;
use Maguari\Server\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Adding GCP projects and listing their instances (design section 8.1 items 1
 * and 2).
 */
final class ProjectsController
{
    public function __construct(
        private readonly FleetApi $fleet,
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
        try {
            $project = $this->fleet->project((int) $args['id']);
        } catch (ProjectNotFound) {
            throw new HttpNotFoundException($request);
        }

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
            'error' => $error,
        ]);
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
