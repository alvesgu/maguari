<?php

declare(strict_types=1);

namespace Maguari\Server\Fleet\Gcp;

use Maguari\Server\Fleet\DiscoveredInstance;
use Maguari\Server\Fleet\Exception\ProjectNotAccessible;
use Maguari\Server\Fleet\InstanceList;
use Maguari\Server\Fleet\InstanceStatus;
use Maguari\Server\Fleet\ProjectAccessProblem;
use Maguari\Server\Kernel\HttpClient\HttpClient;
use Maguari\Server\Kernel\HttpClient\HttpClientFailure;
use Maguari\Server\Kernel\HttpClient\HttpRequest;
use Maguari\Server\Kernel\HttpClient\HttpResponse;

/**
 * The Compute Engine REST API adapter (design section 8). Raw API data and
 * error messages stay inside this class (design section 2.1 rule 4).
 */
final class ComputeEngine
{
    private const BASE_URL = 'https://compute.googleapis.com/compute/v1';
    // The API's largest page size. With MAX_PAGES, at most 5,000 instances
    // and 10 API calls per listing.
    private const PAGE_SIZE = 500;
    private const MAX_PAGES = 10;

    public function __construct(
        private readonly HttpClient $http,
        private readonly AccessTokenSource $tokens,
    ) {
    }

    /**
     * Succeeds when Maguari may list instances in the project, which confirms
     * the custom role is granted (design section 8.1 item 1).
     *
     * @throws ProjectNotAccessible
     */
    public function verifyCanListInstances(string $gcpProjectId): void
    {
        $this->get(self::aggregatedInstancesUrl($gcpProjectId, 1, null), $this->accessToken());
    }

    /**
     * Lists the project's instances, following result pages up to MAX_PAGES
     * (design section 8.1 item 2). Any failed page fails the whole list.
     *
     * @throws ProjectNotAccessible
     */
    public function listInstances(string $gcpProjectId): InstanceList
    {
        $token = $this->accessToken();
        $instances = [];
        $unreachableZones = [];
        $pageToken = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $data = $this->get(self::aggregatedInstancesUrl($gcpProjectId, self::PAGE_SIZE, $pageToken), $token);
            self::readPage($data, $instances, $unreachableZones);

            $pageToken = $data['nextPageToken'] ?? null;

            if (!is_string($pageToken) || $pageToken === '') {
                $pageToken = null;
                break;
            }
        }

        usort(
            $instances,
            static fn (DiscoveredInstance $a, DiscoveredInstance $b): int => [$a->name, $a->zone] <=> [$b->name, $b->zone],
        );
        $unreachableZones = array_values(array_unique($unreachableZones));
        sort($unreachableZones);

        return new InstanceList($instances, $unreachableZones, $pageToken !== null);
    }

    /**
     * @throws ProjectNotAccessible
     */
    private function accessToken(): AccessToken
    {
        try {
            return $this->tokens->accessToken();
        } catch (AccessTokenUnavailable $unavailable) {
            throw new ProjectNotAccessible(ProjectAccessProblem::CredentialsUnavailable, $unavailable->getMessage());
        }
    }

    private static function aggregatedInstancesUrl(string $gcpProjectId, int $maxResults, ?string $pageToken): string
    {
        return sprintf(
            '%s/projects/%s/aggregated/instances?maxResults=%d&returnPartialSuccess=true%s',
            self::BASE_URL,
            rawurlencode($gcpProjectId),
            $maxResults,
            $pageToken === null ? '' : '&pageToken=' . rawurlencode($pageToken),
        );
    }

    /**
     * @return array<string, mixed> the decoded JSON body of a successful response
     * @throws ProjectNotAccessible
     */
    private function get(string $url, AccessToken $token): array
    {
        try {
            $response = $this->http->send(HttpRequest::get($url, ['Authorization' => 'Bearer ' . $token->value]));
        } catch (HttpClientFailure) {
            throw new ProjectNotAccessible(ProjectAccessProblem::UnexpectedResponse);
        }

        $data = $response->json();

        if ($response->isSuccessful() && $data !== null) {
            return $data;
        }

        throw new ProjectNotAccessible(self::problem($response));
    }

    /**
     * Adds one page of an aggregated list to $instances and $unreachableZones.
     * Its items are keyed by scope ("zones/us-central1-a"), each holding
     * either instances or a warning.
     *
     * @param array<string, mixed> $data
     * @param DiscoveredInstance[] $instances
     * @param string[] $unreachableZones
     * @throws ProjectNotAccessible when the page does not have the expected shape
     */
    private static function readPage(array $data, array &$instances, array &$unreachableZones): void
    {
        $items = $data['items'] ?? [];
        $unreachables = $data['unreachables'] ?? [];

        if (!is_array($items) || !is_array($unreachables)) {
            throw new ProjectNotAccessible(ProjectAccessProblem::UnexpectedResponse);
        }

        foreach ($items as $scope => $scoped) {
            if (!is_array($scoped)) {
                throw new ProjectNotAccessible(ProjectAccessProblem::UnexpectedResponse);
            }

            $warningCode = $scoped['warning']['code'] ?? null;

            if (isset($scoped['warning']) && $warningCode !== 'NO_RESULTS_ON_PAGE') {
                $unreachableZones[] = self::lastSegment((string) $scope);
            }

            $scopedInstances = $scoped['instances'] ?? [];

            if (!is_array($scopedInstances)) {
                throw new ProjectNotAccessible(ProjectAccessProblem::UnexpectedResponse);
            }

            foreach ($scopedInstances as $instance) {
                $instances[] = self::instance($instance);
            }
        }

        foreach ($unreachables as $unreachable) {
            if (is_string($unreachable) && $unreachable !== '') {
                $unreachableZones[] = self::lastSegment($unreachable);
            }
        }
    }

    /**
     * @throws ProjectNotAccessible when a required field is missing or malformed
     */
    private static function instance(mixed $item): DiscoveredInstance
    {
        $id = is_array($item) ? ($item['id'] ?? null) : null;
        $name = is_array($item) ? ($item['name'] ?? null) : null;
        $zone = is_array($item) ? ($item['zone'] ?? null) : null;
        $status = is_array($item) ? ($item['status'] ?? null) : null;
        $machineType = is_array($item) ? ($item['machineType'] ?? null) : null;

        if (
            !is_string($id) || !ctype_digit($id)
            || !is_string($name) || $name === ''
            || !is_string($zone) || $zone === ''
            || !is_string($status) || $status === ''
        ) {
            throw new ProjectNotAccessible(ProjectAccessProblem::UnexpectedResponse);
        }

        return new DiscoveredInstance(
            $id,
            $name,
            self::lastSegment($zone),
            InstanceStatus::fromComputeEngine($status),
            is_string($machineType) ? self::lastSegment($machineType) : '',
        );
    }

    /**
     * The short name at the end of a resource URL or path, for example
     * e2-micro from .../zones/us-central1-a/machineTypes/e2-micro.
     */
    private static function lastSegment(string $resource): string
    {
        $position = strrpos($resource, '/');

        return $position === false ? $resource : substr($resource, $position + 1);
    }

    private static function problem(HttpResponse $response): ProjectAccessProblem
    {
        if ($response->status === 404) {
            return ProjectAccessProblem::NotFound;
        }

        if ($response->status !== 403) {
            return ProjectAccessProblem::UnexpectedResponse;
        }

        $reasons = self::errorReasons($response);

        if (array_intersect($reasons, ['accessNotConfigured', 'SERVICE_DISABLED']) !== []) {
            return ProjectAccessProblem::ApiDisabled;
        }

        if (array_intersect($reasons, ['ACCESS_TOKEN_SCOPE_INSUFFICIENT', 'insufficientPermissions']) !== []) {
            return ProjectAccessProblem::ScopeInsufficient;
        }

        // Google answers 403 both for missing permission and for projects the
        // caller cannot see, so the two cannot be told apart.
        return ProjectAccessProblem::NotFoundOrNoPermission;
    }

    /**
     * The machine-readable reasons in a Google API error body: the legacy
     * error.errors[].reason and the newer error.details[].reason (ErrorInfo).
     *
     * @return string[]
     */
    private static function errorReasons(HttpResponse $response): array
    {
        $error = $response->json()['error'] ?? null;

        if (!is_array($error)) {
            return [];
        }

        $reasons = [];

        foreach (['errors', 'details'] as $key) {
            foreach (is_array($error[$key] ?? null) ? $error[$key] : [] as $item) {
                if (is_array($item) && is_string($item['reason'] ?? null)) {
                    $reasons[] = $item['reason'];
                }
            }
        }

        return $reasons;
    }
}
