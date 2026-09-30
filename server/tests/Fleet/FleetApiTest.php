<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Fleet;

use Maguari\Server\Fleet\Exception\InvalidProjectId;
use Maguari\Server\Fleet\Exception\ProjectAlreadyAdded;
use Maguari\Server\Fleet\DiscoveredInstance;
use Maguari\Server\Fleet\Exception\ProjectNotAccessible;
use Maguari\Server\Fleet\Exception\ProjectNotFound;
use Maguari\Server\Fleet\Project;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

final class FleetApiTest extends TestCase
{
    private TestEnvironment $environment;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
    }

    protected function tearDown(): void
    {
        $this->environment->cleanUp();
    }

    private function allowListing(): void
    {
        $this->environment->http->queueJson(200, ['kind' => 'compute#instanceAggregatedList']);
    }

    public function testAddsAProjectOnceAccessIsConfirmed(): void
    {
        $this->allowListing();

        $project = $this->environment->fleet->addProject(' My-Project ');

        $this->assertSame('my-project', $project->gcpProjectId);
        $this->assertSame($this->environment->clock->now(), $project->createdAt);
        $this->assertStringContainsString('/projects/my-project/', $this->environment->http->lastRequest()->url);
        $this->assertEquals([$project], $this->environment->fleet->listProjects());
    }

    public function testStoresNothingWhenAccessFails(): void
    {
        $this->environment->http->queueJson(403, ['error' => ['code' => 403, 'errors' => [['reason' => 'forbidden']]]]);

        try {
            $this->environment->fleet->addProject('my-project');
            $this->fail('Expected ProjectNotAccessible.');
        } catch (ProjectNotAccessible) {
        }

        $this->assertSame([], $this->environment->fleet->listProjects());
    }

    public function testRejectsInvalidIdsWithoutCallingTheApi(): void
    {
        try {
            $this->environment->fleet->addProject('example.com:my-project');
            $this->fail('Expected InvalidProjectId.');
        } catch (InvalidProjectId) {
        }

        $this->assertSame([], $this->environment->http->requests);
        $this->assertSame(0, $this->environment->tokens->calls);
    }

    public function testRejectsDuplicatesWithoutCallingTheApi(): void
    {
        $this->allowListing();
        $this->environment->fleet->addProject('my-project');

        try {
            $this->environment->fleet->addProject('MY-PROJECT');
            $this->fail('Expected ProjectAlreadyAdded.');
        } catch (ProjectAlreadyAdded $exception) {
            $this->assertSame('This project is already added.', $exception->getMessage());
        }

        $this->assertCount(1, $this->environment->http->requests);
    }

    public function testListsProjectsInOrderOfAddition(): void
    {
        foreach (['second-project', 'first-project'] as $id) {
            $this->allowListing();
            $this->environment->fleet->addProject($id);
            $this->environment->clock->advance(60);
        }

        $this->assertSame(
            ['second-project', 'first-project'],
            array_map(static fn (Project $project): string => $project->gcpProjectId, $this->environment->fleet->listProjects()),
        );
    }

    public function testListsInstancesOfAProject(): void
    {
        $this->allowListing();
        $project = $this->environment->fleet->addProject('my-project');
        $zone = 'https://www.googleapis.com/compute/v1/projects/my-project/zones/us-east1-b';
        $this->environment->http->queueJson(200, ['items' => ['zones/us-east1-b' => ['instances' => [
            ['id' => '2', 'name' => 'web', 'zone' => $zone, 'status' => 'RUNNING', 'machineType' => $zone . '/machineTypes/e2-small'],
            ['id' => '1', 'name' => 'db', 'zone' => $zone, 'status' => 'RUNNING', 'machineType' => $zone . '/machineTypes/e2-micro'],
        ]]]]);
        $changes = $this->environment->database->pdo()->query('SELECT total_changes()')->fetchColumn();

        $list = $this->environment->fleet->listInstances($project->id);

        $this->assertSame(
            ['db e2-micro', 'web e2-small'],
            array_map(static fn (DiscoveredInstance $instance): string => $instance->name . ' ' . $instance->machineType, $list->instances),
        );
        $this->assertStringContainsString('/projects/my-project/aggregated/instances', $this->environment->http->lastRequest()->url);
        $this->assertSame($changes, $this->environment->database->pdo()->query('SELECT total_changes()')->fetchColumn());
    }

    public function testUnknownProjectIsNotListed(): void
    {
        try {
            $this->environment->fleet->listInstances(42);
            $this->fail('Expected ProjectNotFound.');
        } catch (ProjectNotFound) {
        }

        $this->assertSame([], $this->environment->http->requests);
        $this->assertSame(0, $this->environment->tokens->calls);
    }
}
