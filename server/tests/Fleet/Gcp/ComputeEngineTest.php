<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Fleet\Gcp;

use Maguari\Server\Fleet\DiscoveredInstance;
use Maguari\Server\Fleet\Exception\ProjectNotAccessible;
use Maguari\Server\Fleet\Gcp\ComputeEngine;
use Maguari\Server\Fleet\InstanceStatus;
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

    /**
     * An instance as the Compute Engine API returns it.
     *
     * @return array<string, mixed>
     */
    private static function apiInstance(string $name, string $zone, string $status = 'RUNNING', string $id = '1234567890123456789'): array
    {
        $zoneUrl = 'https://www.googleapis.com/compute/v1/projects/my-project/zones/' . $zone;

        return [
            'kind' => 'compute#instance',
            'id' => $id,
            'name' => $name,
            'zone' => $zoneUrl,
            'status' => $status,
            'machineType' => $zoneUrl . '/machineTypes/e2-micro',
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $items
     * @param array<string, mixed> $extra
     */
    private function queuePage(array $items, array $extra = []): void
    {
        $this->http->queueJson(200, ['kind' => 'compute#instanceAggregatedList', 'items' => $items] + $extra);
    }

    private function listProblem(): ProjectNotAccessible
    {
        try {
            $this->computeEngine->listInstances('my-project');
            $this->fail('Expected ProjectNotAccessible.');
        } catch (ProjectNotAccessible $notAccessible) {
            return $notAccessible;
        }
    }

    public function testListsInstancesFromEveryZone(): void
    {
        $this->queuePage([
            'zones/us-east1-b' => ['instances' => [self::apiInstance('web', 'us-east1-b')]],
            'zones/us-central1-a' => ['instances' => [self::apiInstance('db', 'us-central1-a', 'TERMINATED', '18446744073709551615')]],
            'zones/europe-west1-b' => ['warning' => ['code' => 'NO_RESULTS_ON_PAGE', 'message' => 'There are no results for scope']],
        ]);

        $list = $this->computeEngine->listInstances('my-project');

        $this->assertEquals([
            new DiscoveredInstance('18446744073709551615', 'db', 'us-central1-a', InstanceStatus::Terminated, 'e2-micro'),
            new DiscoveredInstance('1234567890123456789', 'web', 'us-east1-b', InstanceStatus::Running, 'e2-micro'),
        ], $list->instances);
        $this->assertSame([], $list->unreachableZones);
        $this->assertFalse($list->truncated);

        $request = $this->http->lastRequest();
        $this->assertSame(
            'https://compute.googleapis.com/compute/v1/projects/my-project/aggregated/instances?maxResults=500&returnPartialSuccess=true',
            $request->url,
        );
        $this->assertSame('Bearer ' . FakeTokenSource::TOKEN, $request->headers['Authorization']);
    }

    public function testSortsByNameThenZone(): void
    {
        $this->queuePage([
            'zones/us-east1-b' => ['instances' => [self::apiInstance('web', 'us-east1-b'), self::apiInstance('app', 'us-east1-b')]],
            'zones/us-central1-a' => ['instances' => [self::apiInstance('web', 'us-central1-a')]],
        ]);

        $list = $this->computeEngine->listInstances('my-project');

        $this->assertSame(
            ['app us-east1-b', 'web us-central1-a', 'web us-east1-b'],
            array_map(static fn (DiscoveredInstance $instance): string => $instance->name . ' ' . $instance->zone, $list->instances),
        );
    }

    public function testEmptyProject(): void
    {
        $this->http->queueJson(200, ['kind' => 'compute#instanceAggregatedList']);

        $list = $this->computeEngine->listInstances('my-project');

        $this->assertSame([], $list->instances);
        $this->assertFalse($list->truncated);
    }

    public function testFollowsPagesWithOneToken(): void
    {
        $this->queuePage(['zones/us-east1-b' => ['instances' => [self::apiInstance('a', 'us-east1-b')]]], ['nextPageToken' => 'page 2/token']);
        $this->queuePage(['zones/us-east1-b' => ['instances' => [self::apiInstance('b', 'us-east1-b')]]]);

        $list = $this->computeEngine->listInstances('my-project');

        $this->assertCount(2, $list->instances);
        $this->assertCount(2, $this->http->requests);
        $this->assertStringEndsWith('&pageToken=page%202%2Ftoken', $this->http->lastRequest()->url);
        $this->assertSame(1, $this->tokens->calls);
        $this->assertFalse($list->truncated);
    }

    public function testStopsAfterTenPages(): void
    {
        for ($page = 0; $page < 10; $page++) {
            $this->queuePage(['zones/us-east1-b' => ['instances' => [self::apiInstance('i' . $page, 'us-east1-b')]]], ['nextPageToken' => 'next']);
        }

        $list = $this->computeEngine->listInstances('my-project');

        $this->assertCount(10, $this->http->requests);
        $this->assertCount(10, $list->instances);
        $this->assertTrue($list->truncated);
    }

    public function testNamesUnreachableZones(): void
    {
        $this->queuePage(
            [
                'zones/us-east1-b' => ['instances' => [self::apiInstance('web', 'us-east1-b')]],
                'zones/us-west1-a' => ['warning' => ['code' => 'UNREACHABLE', 'message' => 'raw google message']],
            ],
            ['unreachables' => ['zones/us-east1-c', 'zones/us-west1-a']],
        );

        $list = $this->computeEngine->listInstances('my-project');

        $this->assertCount(1, $list->instances);
        $this->assertSame(['us-east1-c', 'us-west1-a'], $list->unreachableZones);
    }

    /**
     * @return array<string, array{string, InstanceStatus}>
     */
    public static function statuses(): array
    {
        return [
            'provisioning' => ['PROVISIONING', InstanceStatus::Provisioning],
            'staging' => ['STAGING', InstanceStatus::Staging],
            'running' => ['RUNNING', InstanceStatus::Running],
            'stopping' => ['STOPPING', InstanceStatus::Stopping],
            'stopped' => ['STOPPED', InstanceStatus::Stopped],
            'suspending' => ['SUSPENDING', InstanceStatus::Suspending],
            'suspended' => ['SUSPENDED', InstanceStatus::Suspended],
            'repairing' => ['REPAIRING', InstanceStatus::Repairing],
            'terminated' => ['TERMINATED', InstanceStatus::Terminated],
            'added by Google later' => ['HIBERNATING', InstanceStatus::Unknown],
        ];
    }

    /**
     * @dataProvider statuses
     */
    public function testTranslatesStatuses(string $apiStatus, InstanceStatus $expected): void
    {
        $this->queuePage(['zones/us-east1-b' => ['instances' => [self::apiInstance('web', 'us-east1-b', $apiStatus)]]]);

        $this->assertSame($expected, $this->computeEngine->listInstances('my-project')->instances[0]->status);
    }

    public function testMissingMachineTypeIsEmpty(): void
    {
        $instance = self::apiInstance('web', 'us-east1-b');
        unset($instance['machineType']);
        $this->queuePage(['zones/us-east1-b' => ['instances' => [$instance]]]);

        $this->assertSame('', $this->computeEngine->listInstances('my-project')->instances[0]->machineType);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedPages(): array
    {
        $instance = self::apiInstance('web', 'us-east1-b');
        $without = static function (string $field) use ($instance): array {
            unset($instance[$field]);

            return ['zones/us-east1-b' => ['instances' => [$instance]]];
        };

        return [
            'missing id' => [$without('id')],
            'missing name' => [$without('name')],
            'missing zone' => [$without('zone')],
            'missing status' => [$without('status')],
            'numeric id' => [['zones/us-east1-b' => ['instances' => [['id' => 123] + $instance]]]],
            'instance not an object' => [['zones/us-east1-b' => ['instances' => ['web']]]],
            'instances not a list' => [['zones/us-east1-b' => ['instances' => 'web']]],
            'scope not an object' => [['zones/us-east1-b' => 'web']],
        ];
    }

    /**
     * @dataProvider malformedPages
     * @param array<string, mixed> $items
     */
    public function testMalformedPageIsAnUnexpectedResponse(array $items): void
    {
        $this->queuePage($items);

        $this->assertSame(ProjectAccessProblem::UnexpectedResponse, $this->listProblem()->problem);
    }

    /**
     * @dataProvider failures
     */
    public function testListingMapsFailures(HttpResponse $response, ProjectAccessProblem $expected): void
    {
        $this->http->queue($response);

        $this->assertSame($expected, $this->listProblem()->problem);
    }

    public function testListingCredentialsUnavailable(): void
    {
        $this->tokens->failWith('No Application Default Credentials found.');

        $this->assertSame(ProjectAccessProblem::CredentialsUnavailable, $this->listProblem()->problem);
        $this->assertSame([], $this->http->requests);
    }

    public function testFailureOnALaterPageFailsTheWholeList(): void
    {
        $this->queuePage(['zones/us-east1-b' => ['instances' => [self::apiInstance('a', 'us-east1-b')]]], ['nextPageToken' => 'next']);
        $this->http->queueFailure();

        $this->assertSame(ProjectAccessProblem::UnexpectedResponse, $this->listProblem()->problem);
    }
}
