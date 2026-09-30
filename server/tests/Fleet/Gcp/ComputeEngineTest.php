<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Fleet\Gcp;

use Maguari\Server\Fleet\Exception\ProjectNotAccessible;
use Maguari\Server\Fleet\Gcp\ComputeEngine;
use Maguari\Server\Fleet\ProjectAccessProblem;
use Maguari\Server\Kernel\HttpClient\HttpResponse;
use Maguari\Server\Tests\Support\FakeHttpClient;
use Maguari\Server\Tests\Support\FakeTokenSource;
use PHPUnit\Framework\TestCase;

final class ComputeEngineTest extends TestCase
{
    private FakeHttpClient $http;
    private FakeTokenSource $tokens;
    private ComputeEngine $computeEngine;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->tokens = new FakeTokenSource();
        $this->computeEngine = new ComputeEngine($this->http, $this->tokens);
    }

    private function problemFor(): ProjectNotAccessible
    {
        try {
            $this->computeEngine->verifyCanListInstances('my-project');
            $this->fail('Expected ProjectNotAccessible.');
        } catch (ProjectNotAccessible $notAccessible) {
            return $notAccessible;
        }
    }

    /**
     * A Google API error body.
     *
     * @param string[] $legacyReasons error.errors[].reason
     * @param string[] $infoReasons error.details[].reason (ErrorInfo)
     * @return array<string, mixed>
     */
    private static function googleError(int $code, string $message, array $legacyReasons = [], array $infoReasons = []): array
    {
        return ['error' => [
            'code' => $code,
            'message' => $message,
            'errors' => array_map(static fn (string $reason): array => ['message' => $message, 'domain' => 'global', 'reason' => $reason], $legacyReasons),
            'details' => array_map(static fn (string $reason): array => ['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => $reason], $infoReasons),
        ]];
    }

    public function testSucceedsWhenInstancesCanBeListed(): void
    {
        $this->http->queueJson(200, ['kind' => 'compute#instanceAggregatedList', 'items' => []]);

        $this->computeEngine->verifyCanListInstances('my-project');

        $request = $this->http->lastRequest();
        $this->assertSame('GET', $request->method);
        $this->assertSame(
            'https://compute.googleapis.com/compute/v1/projects/my-project/aggregated/instances?maxResults=1&returnPartialSuccess=true',
            $request->url,
        );
        $this->assertSame('Bearer ' . FakeTokenSource::TOKEN, $request->headers['Authorization']);
    }

    public function testCredentialsUnavailable(): void
    {
        $this->tokens->failWith('No Application Default Credentials found.');

        $problem = $this->problemFor();

        $this->assertSame(ProjectAccessProblem::CredentialsUnavailable, $problem->problem);
        $this->assertSame('Maguari could not obtain Google Cloud credentials. No Application Default Credentials found.', $problem->getMessage());
        $this->assertSame([], $this->http->requests);
    }

    /**
     * @return array<string, array{HttpResponse, ProjectAccessProblem}>
     */
    public static function failures(): array
    {
        $json = static fn (int $status, array $data): HttpResponse => new HttpResponse($status, [], json_encode($data));

        return [
            'not found' => [
                $json(404, self::googleError(404, "The resource 'projects/my-project' was not found", ['notFound'])),
                ProjectAccessProblem::NotFound,
            ],
            'API disabled (legacy reason)' => [
                $json(403, self::googleError(403, 'Compute Engine API has not been used in project', ['accessNotConfigured'])),
                ProjectAccessProblem::ApiDisabled,
            ],
            'API disabled (ErrorInfo)' => [
                $json(403, self::googleError(403, 'Compute Engine API has not been used in project', [], ['SERVICE_DISABLED'])),
                ProjectAccessProblem::ApiDisabled,
            ],
            'scope insufficient (ErrorInfo)' => [
                $json(403, self::googleError(403, 'Request had insufficient authentication scopes.', [], ['ACCESS_TOKEN_SCOPE_INSUFFICIENT'])),
                ProjectAccessProblem::ScopeInsufficient,
            ],
            'scope insufficient (legacy reason)' => [
                $json(403, self::googleError(403, 'Request had insufficient authentication scopes.', ['insufficientPermissions'])),
                ProjectAccessProblem::ScopeInsufficient,
            ],
            'missing permission' => [
                $json(403, self::googleError(403, "Required 'compute.instances.list' permission for 'projects/my-project'", ['forbidden'])),
                ProjectAccessProblem::NotFoundOrNoPermission,
            ],
            '403 without a JSON body' => [new HttpResponse(403, [], 'Forbidden'), ProjectAccessProblem::NotFoundOrNoPermission],
            'unauthorized' => [$json(401, self::googleError(401, 'Invalid Credentials', ['authError'])), ProjectAccessProblem::UnexpectedResponse],
            'server error' => [new HttpResponse(503, [], 'Service Unavailable'), ProjectAccessProblem::UnexpectedResponse],
            '200 without JSON' => [new HttpResponse(200, [], '<html>'), ProjectAccessProblem::UnexpectedResponse],
        ];
    }

    /**
     * @dataProvider failures
     */
    public function testMapsFailures(HttpResponse $response, ProjectAccessProblem $expected): void
    {
        $this->http->queue($response);

        $problem = $this->problemFor();

        $this->assertSame($expected, $problem->problem);
        $this->assertSame($expected->message(), $problem->getMessage());
    }

    public function testNoResponse(): void
    {
        $this->http->queueFailure();

        $this->assertSame(ProjectAccessProblem::UnexpectedResponse, $this->problemFor()->problem);
    }

    public function testNotFoundOrNoPermissionMessage(): void
    {
        $this->assertStringStartsWith(
            'Project not found, or no permission to access it.',
            ProjectAccessProblem::NotFoundOrNoPermission->message(),
        );
    }
}
