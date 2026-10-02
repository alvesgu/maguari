<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Http;

use Maguari\Server\Tests\Support\Browser;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

final class AdminDashboardTest extends TestCase
{
    private TestEnvironment $environment;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
        $this->environment->createAdministrator();
    }

    protected function tearDown(): void
    {
        $this->environment->cleanUp();
    }

    private function signedInBrowser(): Browser
    {
        $browser = $this->environment->browser();
        $form = $browser->get('/auth/login');
        $browser->post('/auth/login', Browser::csrfFields($form) + [
            'email' => TestEnvironment::ADMINISTRATOR_EMAIL,
            'password' => TestEnvironment::ADMINISTRATOR_PASSWORD,
        ]);

        return $browser;
    }

    private function pick(string $gcpProjectId, string $name): int
    {
        $projects = $this->environment->fleet->listProjects();
        $project = null;

        foreach ($projects as $existing) {
            if ($existing->gcpProjectId === $gcpProjectId) {
                $project = $existing;
            }
        }

        if ($project === null) {
            $this->environment->http->queueJson(200, ['kind' => 'compute#instanceAggregatedList']);
            $project = $this->environment->fleet->addProject($gcpProjectId);
        }

        $zone = 'https://www.googleapis.com/compute/v1/projects/' . $gcpProjectId . '/zones/us-east1-b';
        $this->environment->http->queueJson(200, ['id' => '1', 'name' => $name, 'zone' => $zone, 'status' => 'RUNNING']);

        return $this->environment->fleet->pickInstance($project->id, 'us-east1-b', $name)->id;
    }

    public function testEmptyDashboard(): void
    {
        $body = (string) $this->signedInBrowser()->get('/admin')->getBody();

        $this->assertStringContainsString('No instances yet.', $body);
    }

    public function testShowsEveryPickedInstanceWithItsHeartbeatStatus(): void
    {
        $waiting = $this->pick('alpha-project', 'web');
        $enrolled = $this->pick('alpha-project', 'db');
        $this->pick('beta-project', 'app');
        $this->environment->clients->issueEnrollmentToken($waiting);
        $client = $this->environment->enrollClient($enrolled);
        $this->environment->clients->recordHeartbeat($client->clientId, json_encode([
            'protocol_version' => 1, 'client_version' => '0.1.0', 'client_id' => $client->clientId, 'sent_at' => 0,
        ]));
        $this->environment->clock->advance(45);
        $requests = count($this->environment->http->requests);

        $response = $this->signedInBrowser()->get('/admin');

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $heartbeatAt = gmdate('Y-m-d H:i:s', $this->environment->clock->now() - 45);
        $this->assertStringContainsString(
            '<tr><td><a href="/admin/projects/1">alpha-project</a></td><td>db</td><td>us-east1-b</td>' . "\n"
                . '<td>Enrolled</td>' . "\n" . '<td>On time</td>' . "\n" . '<td>' . $heartbeatAt . ' UTC (45 s ago)</td>' . "\n"
                . '<td>0.1.0</td>' . "\n" . '<td></td></tr>',
            $body,
        );
        $this->assertStringContainsString('<td>web</td><td>us-east1-b</td>' . "\n" . '<td>Waiting for enrollment</td>', $body);
        $this->assertStringContainsString('<td>beta-project</a></td><td>app</td><td>us-east1-b</td>' . "\n" . '<td>Not enrolled</td>', str_replace('<a href="/admin/projects/2">', '', $body));
        $this->assertLessThan(strpos($body, '>beta-project<'), strpos($body, '>alpha-project<'));
        // Only SQLite: no Compute Engine call, so the dashboard works while Google Cloud's API does not.
        $this->assertCount($requests, $this->environment->http->requests);
    }

    public function testLateHeartbeat(): void
    {
        $client = $this->environment->enrollClient($this->pick('alpha-project', 'db'));
        $this->environment->clients->recordHeartbeat($client->clientId, json_encode([
            'protocol_version' => 1, 'client_version' => '0.1.0', 'client_id' => $client->clientId, 'sent_at' => 0,
        ]));
        $this->environment->clock->advance(600);

        $body = (string) $this->signedInBrowser()->get('/admin')->getBody();

        $this->assertStringContainsString("<td>Late</td>\n", $body);
        $this->assertStringContainsString('UTC (10 min ago)', $body);
    }
}
