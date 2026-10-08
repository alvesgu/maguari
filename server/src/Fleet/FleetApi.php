<?php

declare(strict_types=1);

namespace Maguari\Server\Fleet;

use Maguari\Server\Fleet\Exception\InstanceNotFound;
use Maguari\Server\Fleet\Exception\InvalidInstanceName;
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
    private readonly InstanceRepository $instances;
    private readonly ComputeEngine $computeEngine;

    public function __construct(
        Database $database,
        private readonly Clock $clock,
        AccessTokenSource $tokens,
        HttpClient $http,
    ) {
        $this->projects = new ProjectRepository($database);
        $this->instances = new InstanceRepository($database);
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

    /**
     * Picks an instance for enrollment (design section 8.1 item 2), after the
     * Compute Engine API confirms it exists. Picking it again refreshes its
     * GCP instance ID and returns the same instance.
     *
     * @throws ProjectNotFound
     * @throws InvalidInstanceName
     * @throws InstanceNotFound
     * @throws ProjectNotAccessible
     */
    public function pickInstance(int $projectId, string $zone, string $name): Instance
    {
        $project = $this->project($projectId);
        InstanceName::validate($zone, $name);
        $discovered = $this->computeEngine->getInstance($project->gcpProjectId, $zone, $name);

        return $this->instances->save($project->id, $discovered->gcpInstanceId, $zone, $name, $this->clock->now());
    }

    /**
     * One picked instance, from SQLite only; null when no instance has that ID.
     */
    public function pickedInstance(int $instanceId): ?Instance
    {
        return $this->instances->find($instanceId);
    }

    /**
     * @param int|null $projectId one project's instances, or null for every project's
     * @return Instance[] sorted by name, then zone (by GCP project ID first for every project)
     */
    public function pickedInstances(?int $projectId = null): array
    {
        return $projectId === null ? $this->instances->all() : $this->instances->inProject($projectId);
    }

    /**
     * Every picked instance's attached disks, from one Compute Engine listing
     * per project that has picked instances (design section 6.3). Instances
     * are matched by zone and name. A project whose listing fails gives each
     * of its instances that failure's sentence. Nothing is stored.
     *
     * @return array<int, InstanceDisks> by instance ID, for every picked instance
     */
    public function diskSizes(): array
    {
        $byProject = [];

        foreach ($this->instances->all() as $instance) {
            $byProject[$instance->gcpProjectId][] = $instance;
        }

        $disks = [];

        foreach ($byProject as $gcpProjectId => $instances) {
            try {
                $list = $this->computeEngine->listInstances((string) $gcpProjectId);
            } catch (ProjectNotAccessible $notAccessible) {
                foreach ($instances as $instance) {
                    $disks[$instance->id] = InstanceDisks::unavailable($notAccessible->getMessage());
                }

                continue;
            }

            $listed = [];

            foreach ($list->instances as $discovered) {
                $listed[$discovered->zone . '/' . $discovered->name] = $discovered;
            }

            foreach ($instances as $instance) {
                $discovered = $listed[$instance->zone . '/' . $instance->name] ?? null;
                $disks[$instance->id] = $discovered === null
                    ? InstanceDisks::unavailable(self::missingFromList($instance, $list))
                    : InstanceDisks::listed($discovered->disks);
            }
        }

        return $disks;
    }

    private static function missingFromList(Instance $instance, InstanceList $list): string
    {
        if (in_array($instance->zone, $list->unreachableZones, true)) {
            return 'Google Cloud could not list the instances in this instance\'s zone.';
        }

        if ($list->truncated) {
            return 'This instance was not in the listing, which was cut short because the project has too many instances.';
        }

        return 'This instance was not found in the project.';
    }
}
