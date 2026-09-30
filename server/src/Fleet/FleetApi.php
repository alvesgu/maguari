<?php

declare(strict_types=1);

namespace Maguari\Server\Fleet;

use Maguari\Server\Fleet\Exception\InvalidProjectId;
use Maguari\Server\Fleet\Exception\ProjectAlreadyAdded;
use Maguari\Server\Fleet\Exception\ProjectNotAccessible;
use Maguari\Server\Fleet\Exception\ProjectNotFound;
use Maguari\Server\Fleet\Gcp\AccessTokenSource;
use Maguari\Server\Fleet\Gcp\ComputeEngine;
use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Kernel\HttpClient\HttpClient;

/**
 * The Fleet context's public interface: projects, instances and the GCP API.
 */
final class FleetApi
{
    private readonly ProjectRepository $projects;
    private readonly ComputeEngine $computeEngine;

    public function __construct(
        Database $database,
        private readonly Clock $clock,
        AccessTokenSource $tokens,
        HttpClient $http,
    ) {
        $this->projects = new ProjectRepository($database);
        $this->computeEngine = new ComputeEngine($http, $tokens);
    }

    /**
     * @return Project[] in order of addition
     */
    public function listProjects(): array
    {
        return $this->projects->all();
    }

    /**
     * Adds a project once Maguari has confirmed it can list instances there
     * (design section 8.1 item 1). Nothing is stored when the check fails.
     *
     * @throws InvalidProjectId
     * @throws ProjectAlreadyAdded
     * @throws ProjectNotAccessible
     */
    public function addProject(string $gcpProjectId): Project
    {
        $gcpProjectId = ProjectId::normalize($gcpProjectId);

        if ($this->projects->exists($gcpProjectId)) {
            throw new ProjectAlreadyAdded('This project is already added.');
        }

        // Not inside a transaction: the API call takes seconds, and holding the
        // write lock that long would block other requests. The UNIQUE
        // constraint catches a concurrent add instead.
        $this->computeEngine->verifyCanListInstances($gcpProjectId);

        return $this->projects->create($gcpProjectId, $this->clock->now());
    }

    /**
     * @throws ProjectNotFound
     */
    public function project(int $projectId): Project
    {
        return $this->projects->find($projectId) ?? throw new ProjectNotFound('This project was not found.');
    }

    /**
     * Lists the project's instances from the Compute Engine API (design
     * section 8.1 item 2). Nothing is stored.
     *
     * @throws ProjectNotFound
     * @throws ProjectNotAccessible
     */
    public function listInstances(int $projectId): InstanceList
    {
        return $this->computeEngine->listInstances($this->project($projectId)->gcpProjectId);
    }
}
