<?php

declare(strict_types=1);

namespace Maguari\Server\Fleet\Gcp;

use Maguari\Server\Fleet\Exception\ProjectNotAccessible;
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
        try {
            $token = $this->tokens->accessToken();
        } catch (AccessTokenUnavailable $unavailable) {
            throw new ProjectNotAccessible(ProjectAccessProblem::CredentialsUnavailable, $unavailable->getMessage());
        }

        $url = sprintf(
            '%s/projects/%s/aggregated/instances?maxResults=1&returnPartialSuccess=true',
            self::BASE_URL,
            rawurlencode($gcpProjectId),
        );

        try {
            $response = $this->http->send(HttpRequest::get($url, ['Authorization' => 'Bearer ' . $token->value]));
        } catch (HttpClientFailure) {
            throw new ProjectNotAccessible(ProjectAccessProblem::UnexpectedResponse);
        }

        if ($response->isSuccessful() && $response->json() !== null) {
            return;
        }

        throw new ProjectNotAccessible(self::problem($response));
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
