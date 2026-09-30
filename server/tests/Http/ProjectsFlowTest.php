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
        $this->assertStringContainsString('<td>my-project</td>', $page);
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
}
