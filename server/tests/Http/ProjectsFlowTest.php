<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Http;

use Maguari\Server\Tests\Support\Browser;
use Maguari\Server\Tests\Support\FakeTokenSource;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class ProjectsFlowTest extends TestCase
{
    private TestEnvironment $environment;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
        $this->environment->createAdministrator();
        $this->environment->access->setBaseUrl('https://maguari.example.com');
    }

    protected function tearDown(): void
    {
        $this->environment->cleanUp();
    }

    private function signedInBrowser(): Browser
    {
        $browser = $this->environment->browser();
        $form = $browser->get('/auth/login');
        $response = $browser->post('/auth/login', Browser::csrfFields($form) + [
            'email' => TestEnvironment::ADMINISTRATOR_EMAIL,
            'password' => TestEnvironment::ADMINISTRATOR_PASSWORD,
        ]);
        $this->assertSame(303, $response->getStatusCode());

        return $browser;
    }

    private function addProject(Browser $browser, string $projectId): ResponseInterface
    {
        $page = $browser->get('/admin/projects');

        return $browser->post('/admin/projects', Browser::csrfFields($page) + ['project_id' => $projectId]);
    }

    public function testRequiresSignIn(): void
    {
        $browser = $this->environment->browser();

        $this->assertSame('/auth/login', $browser->get('/admin/projects')->getHeaderLine('Location'));
        // The CSRF check runs before the sign-in check in the /admin group.
        $this->assertSame(400, $browser->post('/admin/projects', ['project_id' => 'my-project'])->getStatusCode());
        $this->assertSame([], $this->environment->http->requests);
        $this->assertSame([], $this->environment->fleet->listProjects());
    }

    public function testDashboardLinksToProjects(): void
    {
        $this->assertStringContainsString('href="/admin/projects"', (string) $this->signedInBrowser()->get('/admin')->getBody());
    }

    public function testAddsAProject(): void
    {
        $browser = $this->signedInBrowser();
        $this->assertStringContainsString('No projects yet.', (string) $browser->get('/admin/projects')->getBody());
        $this->environment->http->queueJson(200, ['kind' => 'compute#instanceAggregatedList']);

        $response = $this->addProject($browser, 'my-project');

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/admin/projects', $response->getHeaderLine('Location'));
        $page = (string) $browser->get('/admin/projects')->getBody();
        $this->assertStringContainsString('<td><a href="/admin/projects/1">my-project</a></td>', $page);
        $this->assertStringNotContainsString(FakeTokenSource::TOKEN, $page);
    }

    public function testInvalidProjectId(): void
    {
        $response = $this->addProject($this->signedInBrowser(), 'Not A Project!');

        $this->assertSame(422, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Enter a valid project ID', $body);
        $this->assertStringContainsString('value="Not A Project!"', $body);
        $this->assertSame([], $this->environment->http->requests);
    }

    public function testAccessFailureShowsTheReasonAndStoresNothing(): void
    {
        $this->environment->http->queueJson(403, ['error' => [
            'code' => 403,
            'message' => 'raw google message',
            'errors' => [['reason' => 'forbidden']],
        ]]);

        $response = $this->addProject($this->signedInBrowser(), 'my-project');

        $this->assertSame(422, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Project not found, or no permission to access it.', $body);
        $this->assertStringNotContainsString('raw google message', $body);
        $this->assertStringNotContainsString(FakeTokenSource::TOKEN, $body);
        $this->assertSame([], $this->environment->fleet->listProjects());
    }

    public function testCredentialsFailureShowsTheHint(): void
    {
        $this->environment->tokens->failWith('For development, set MAGUARI_GCP_CREDENTIALS=application-default.');

        $response = $this->addProject($this->signedInBrowser(), 'my-project');

        $this->assertSame(422, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Maguari could not obtain Google Cloud credentials.', $body);
        $this->assertStringContainsString('MAGUARI_GCP_CREDENTIALS=application-default', $body);
    }

    public function testDuplicateProject(): void
    {
        $browser = $this->signedInBrowser();
        $this->environment->http->queueJson(200, ['kind' => 'compute#instanceAggregatedList']);
        $this->addProject($browser, 'my-project');

        $response = $this->addProject($browser, 'my-project');

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('This project is already added.', (string) $response->getBody());
    }

    public function testMissingCsrfTokenIsRejected(): void
    {
        $response = $this->signedInBrowser()->post('/admin/projects', ['project_id' => 'my-project']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->environment->http->requests);
        $this->assertSame([], $this->environment->fleet->listProjects());
    }

    /**
     * Adds my-project and returns its page's path.
     */
    private function addedProjectPath(Browser $browser): string
    {
        $this->environment->http->queueJson(200, ['kind' => 'compute#instanceAggregatedList']);
        $this->assertSame(303, $this->addProject($browser, 'my-project')->getStatusCode());

        return '/admin/projects/' . $this->environment->fleet->listProjects()[0]->id;
    }

    public function testProjectPageRequiresSignIn(): void
    {
        $path = $this->addedProjectPath($this->signedInBrowser());

        $this->assertSame('/auth/login', $this->environment->browser('192.0.2.11')->get($path)->getHeaderLine('Location'));
    }

    public function testListsInstances(): void
    {
        $browser = $this->signedInBrowser();
        $path = $this->addedProjectPath($browser);
        $zone = 'https://www.googleapis.com/compute/v1/projects/my-project/zones/us-east1-b';
        $this->environment->http->queueJson(200, [
            'items' => ['zones/us-east1-b' => ['instances' => [
                ['id' => '7', 'name' => 'web-1', 'zone' => $zone, 'status' => 'RUNNING', 'machineType' => $zone . '/machineTypes/e2-micro'],
            ]]],
            'unreachables' => ['zones/us-west1-a'],
        ]);

        $response = $browser->get($path);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('<h1>my-project</h1>', $body);
        $this->assertStringContainsString("<tr><td>web-1</td><td>us-east1-b</td><td>Running</td><td>e2-micro</td>\n<td>Not enrolled</td>", $body);
        $this->assertStringNotContainsString('machineTypes/', $body);
        $this->assertStringContainsString('Some zones could not be reached: us-west1-a.', $body);
        $this->assertStringNotContainsString(FakeTokenSource::TOKEN, $body);
    }

    public function testEmptyProject(): void
    {
        $browser = $this->signedInBrowser();
        $path = $this->addedProjectPath($browser);
        $this->environment->http->queueJson(200, ['kind' => 'compute#instanceAggregatedList']);

        $this->assertStringContainsString('No instances in this project.', (string) $browser->get($path)->getBody());
    }

    public function testListingFailureShowsTheReason(): void
    {
        $browser = $this->signedInBrowser();
        $path = $this->addedProjectPath($browser);
        $this->environment->http->queueJson(403, ['error' => [
            'code' => 403,
            'message' => 'raw google message',
            'errors' => [['reason' => 'accessNotConfigured']],
        ]]);

        $response = $browser->get($path);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('The Compute Engine API is not enabled in this project.', $body);
        $this->assertStringNotContainsString('raw google message', $body);
        $this->assertStringNotContainsString('<table>', $body);
    }

    public function testUnknownProjectIsNotFound(): void
    {
        $browser = $this->signedInBrowser();

        $this->assertSame(404, $browser->get('/admin/projects/999')->getStatusCode());
        $this->assertSame(404, $browser->get('/admin/projects/my-project')->getStatusCode());
        $this->assertSame([], $this->environment->http->requests);
    }

    private function queueListing(string ...$names): void
    {
        $zone = 'https://www.googleapis.com/compute/v1/projects/my-project/zones/us-east1-b';
        $this->environment->http->queueJson(200, ['items' => ['zones/us-east1-b' => ['instances' => array_map(
            static fn (string $name): array => ['id' => '7', 'name' => $name, 'zone' => $zone, 'status' => 'RUNNING'],
            $names,
        )]]]);
    }

    /**
     * @return array<string, string> instances.get's answer for an instance in us-east1-b
     */
    private static function apiInstance(string $name): array
    {
        return ['id' => '7', 'name' => $name, 'zone' => 'https://www.googleapis.com/compute/v1/projects/my-project/zones/us-east1-b', 'status' => 'RUNNING'];
    }

    /**
     * Opens the project page (listing web-1), then queues $answer for
     * instances.get and presses web-1's Enroll button.
     *
     * @param array<string, mixed>|null $answer instances.get's JSON answer; null queues none
     * @param array<string, string> $fields overrides the form's zone and name
     */
    private function pressEnroll(Browser $browser, string $path, ?array $answer, array $fields = [], int $status = 200): ResponseInterface
    {
        $this->queueListing('web-1');
        $page = $browser->get($path);

        if ($answer !== null) {
            $this->environment->http->queueJson($status, $answer);
        }

        return $browser->post($path . '/instances', $fields + Browser::csrfFields($page) + [
            'zone' => 'us-east1-b',
            'name' => 'web-1',
        ]);
    }

    public function testProjectPageHasAnEnrollButtonPerInstance(): void
    {
        $browser = $this->signedInBrowser();
        $path = $this->addedProjectPath($browser);
        $this->queueListing('web-1');

        $body = (string) $browser->get($path)->getBody();

        $this->assertStringContainsString('<form method="post" action="' . $path . '/instances">', $body);
        $this->assertStringContainsString('<input type="hidden" name="zone" value="us-east1-b"><input type="hidden" name="name" value="web-1"><button type="submit">Enroll</button>', $body);
    }

    public function testEnrollShowsTheCommandOnce(): void
    {
        $browser = $this->signedInBrowser();
        $path = $this->addedProjectPath($browser);

        $response = $this->pressEnroll($browser, $path, self::apiInstance('web-1'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $body = (string) $response->getBody();
        $this->assertSame(1, preg_match('#maguari-client enroll --server=https://maguari\.example\.com --token=([A-Za-z0-9_-]{43})#', $body, $match));
        $token = $match[1];
        $this->assertStringContainsString('<h1>Enroll web-1</h1>', $body);
        $this->assertStringContainsString('until 2026-09-21 15:13 UTC', $body);
        $this->assertStringEndsWith('/zones/us-east1-b/instances/web-1', $this->environment->http->lastRequest()->url);

        $stored = $this->environment->database->pdo()->query('SELECT token_hash FROM clients_enrollment_tokens')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([hash('sha256', $token)], $stored);

        $this->queueListing('web-1');
        $page = (string) $browser->get($path)->getBody();
        $this->assertStringContainsString('<td>Waiting for enrollment</td>', $page);
        $this->assertStringNotContainsString($token, $page);
    }

    public function testEnrollingAgainReplacesTheToken(): void
    {
        $browser = $this->signedInBrowser();
        $path = $this->addedProjectPath($browser);
        $this->pressEnroll($browser, $path, self::apiInstance('web-1'));

        $this->assertSame(200, $this->pressEnroll($browser, $path, self::apiInstance('web-1'))->getStatusCode());

        $this->assertSame(1, (int) $this->environment->database->pdo()->query('SELECT COUNT(*) FROM clients_enrollment_tokens')->fetchColumn());
        $this->assertCount(1, $this->environment->fleet->pickedInstances(1));
    }

    public function testInvalidInstanceNameIsRejectedWithoutCallingTheApi(): void
    {
        $browser = $this->signedInBrowser();
        $path = $this->addedProjectPath($browser);

        $response = $this->pressEnroll($browser, $path, null, ['name' => '../other']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('This is not a valid zone and instance name.', (string) $response->getBody());
        $this->assertStringContainsString('/instances?', $this->environment->http->lastRequest()->url);
        $this->assertSame(0, (int) $this->environment->database->pdo()->query('SELECT COUNT(*) FROM clients_enrollment_tokens')->fetchColumn());
    }

    public function testInstanceMissingFromTheApi(): void
    {
        $browser = $this->signedInBrowser();
        $path = $this->addedProjectPath($browser);

        $response = $this->pressEnroll($browser, $path, ['error' => ['code' => 404, 'message' => 'raw google message']], status: 404);

        $this->assertSame(422, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('This instance was not found in the project.', $body);
        $this->assertStringNotContainsString('raw google message', $body);
        $this->assertStringNotContainsString('--token=', $body);
        $this->assertSame([], $this->environment->fleet->pickedInstances(1));
    }

    public function testEnrollRequiresCsrfAndSignIn(): void
    {
        $browser = $this->signedInBrowser();
        $path = $this->addedProjectPath($browser);
        $requests = count($this->environment->http->requests);

        $this->assertSame(400, $browser->post($path . '/instances', ['zone' => 'us-east1-b', 'name' => 'web-1'])->getStatusCode());

        $stranger = $this->environment->browser('192.0.2.11');
        $form = $stranger->get('/auth/login');
        $response = $stranger->post($path . '/instances', Browser::csrfFields($form) + ['zone' => 'us-east1-b', 'name' => 'web-1']);
        $this->assertSame('/auth/login', $response->getHeaderLine('Location'));

        $this->assertCount($requests, $this->environment->http->requests);
        $this->assertSame(0, (int) $this->environment->database->pdo()->query('SELECT COUNT(*) FROM clients_enrollment_tokens')->fetchColumn());
    }

    public function testEnrollInUnknownProjectIsNotFound(): void
    {
        $browser = $this->signedInBrowser();
        $form = $browser->get('/admin/projects');

        $response = $browser->post('/admin/projects/999/instances', Browser::csrfFields($form) + ['zone' => 'us-east1-b', 'name' => 'web-1']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([], $this->environment->http->requests);
    }

    public function testEnrollCommandIgnoresTheRequestsHost(): void
    {
        $browser = $this->signedInBrowser();
        $path = $this->addedProjectPath($browser);
        $this->queueListing('web-1');
        $page = $browser->get($path);
        $this->environment->http->queueJson(200, self::apiInstance('web-1'));

        $response = $browser->post('https://attacker.example.net' . $path . '/instances', Browser::csrfFields($page) + [
            'zone' => 'us-east1-b',
            'name' => 'web-1',
        ]);

        $body = (string) $response->getBody();
        $this->assertStringContainsString('--server=https://maguari.example.com --token=', $body);
        $this->assertStringNotContainsString('attacker', $body);
    }

    public function testEnrollWithoutAConfiguredAddressIssuesNoToken(): void
    {
        $this->environment->database->pdo()->exec("DELETE FROM access_settings WHERE name = 'base_url'");
        $browser = $this->signedInBrowser();
        $path = $this->addedProjectPath($browser);

        $response = $this->pressEnroll($browser, $path, null);

        $this->assertSame(409, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('maguari-server set-base-url --base-url=', $body);
        $this->assertStringNotContainsString('--token=', $body);
        $this->assertStringContainsString('/aggregated/instances', $this->environment->http->lastRequest()->url);
        $this->assertSame([], $this->environment->fleet->pickedInstances(1));
        $this->assertSame(0, (int) $this->environment->database->pdo()->query('SELECT COUNT(*) FROM clients_enrollment_tokens')->fetchColumn());
    }

    public function testEnrolledInstanceShowsAsEnrolled(): void
    {
        $browser = $this->signedInBrowser();
        $path = $this->addedProjectPath($browser);
        $enrollPage = (string) $this->pressEnroll($browser, $path, self::apiInstance('web-1'))->getBody();
        preg_match('#--token=([A-Za-z0-9_-]{43})#', $enrollPage, $match);
        $this->environment->clients->enroll(json_encode(['protocol_version' => 1, 'client_version' => '0.1.0', 'token' => $match[1]]));
        $this->queueListing('web-1');

        $body = (string) $browser->get($path)->getBody();
        $this->assertStringContainsString("<td>Enrolled</td>\n<td>No heartbeat yet</td>", $body);
    }
}
