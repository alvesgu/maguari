<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Http;

use Maguari\Server\Kernel\HttpProbe\HttpProbeFailureKind;
use Maguari\Server\Tests\Support\Browser;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The instance page's "Web apps" (remote HTTP checks, design section 6.2.1)
 * and the dashboard's Checks column.
 */
final class HttpChecksFlowTest extends TestCase
{
    private TestEnvironment $environment;
    private int $instanceId;

    protected function setUp(): void
    {
        $this->environment = new TestEnvironment();
        $this->environment->createAdministrator();
        $this->environment->http->queueJson(200, ['kind' => 'compute#instanceAggregatedList']);
        $this->environment->fleet->addProject('my-project');
        $zoneUrl = 'https://www.googleapis.com/compute/v1/projects/my-project/zones/us-east1-b';
        $this->environment->http->queueJson(200, ['id' => '1', 'name' => 'web', 'zone' => $zoneUrl, 'status' => 'RUNNING']);
        $this->instanceId = $this->environment->fleet->pickInstance(1, 'us-east1-b', 'web')->id;
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

    private function page(): string
    {
        return '/admin/instances/' . $this->instanceId;
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(Browser $browser, string $path, array $fields = []): ResponseInterface
    {
        return $browser->post($this->page() . $path, Browser::csrfFields($browser->get($this->page())) + $fields);
    }

    /**
     * @return list<array{string, int}> URL and interval in minutes
     */
    private function checks(): array
    {
        return array_map(static fn ($check): array => [$check->url, $check->intervalMinutes()], $this->environment->monitoring->httpChecks($this->instanceId));
    }

    public function testAddChangeAndRemoveAWebApp(): void
    {
        $browser = $this->signedInBrowser();
        $empty = (string) $browser->get($this->page())->getBody();
        $this->assertStringContainsString('<h2>Web apps</h2>', $empty);
        $this->assertStringContainsString('9 MB a month at the default 10 minutes, 91 MB at 1 minute. All web app checks together use about 0 MB a month. Up to 200 MB is suggested; above it a change saves with a warning, and above 500 MB it is refused.', $empty);
        $this->assertStringContainsString('<input id="interval" name="interval" type="number" min="1" max="60" value="10">', $empty);

        $response = $this->post($browser, '/http-checks', ['url' => 'https://www.example.com', 'interval' => '']);

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame($this->page(), $response->getHeaderLine('Location'));
        $this->assertSame([['https://www.example.com/', 10]], $this->checks());
        $this->assertSame([], $this->environment->httpProbe->requests, 'Adding connects to nothing.');
        $body = (string) $browser->get($this->page())->getBody();
        $this->assertStringContainsString('<tr><td>https://www.example.com/</td><td>Not checked', $body);
        $this->assertStringContainsString('All web app checks together use about 9 MB', $body);
        $id = $this->environment->monitoring->httpChecks($this->instanceId)[0]->id;

        $this->assertSame(303, $this->post($browser, "/http-checks/{$id}/interval", ['interval' => '30'])->getStatusCode());
        $this->assertSame([['https://www.example.com/', 30]], $this->checks());

        $this->assertSame(303, $this->post($browser, "/http-checks/{$id}/remove")->getStatusCode());
        $this->assertSame([], $this->checks());
        $this->assertSame(303, $this->post($browser, "/http-checks/{$id}/remove")->getStatusCode(), 'A second click just shows the page.');
        $this->assertSame(303, $this->post($browser, "/http-checks/{$id}/interval", ['interval' => '5'])->getStatusCode());
    }

    public function testRefusalsKeepWhatWasTyped(): void
    {
        $browser = $this->signedInBrowser();

        $response = $this->post($browser, '/http-checks', ['url' => 'http://localhost:8000/', 'interval' => '7']);

        $this->assertSame(422, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('<p>The URL needs a full hostname, such as www.example.com: not an IP address or a single name like localhost.</p>', $body);
        $this->assertStringContainsString('value="http://localhost:8000/"', $body);
        $this->assertStringContainsString('value="7"', $body);
        $this->assertSame([], $this->checks());

        $this->environment->monitoring->addHttpCheck($this->instanceId, 'https://www.example.com/', '');
        $id = $this->environment->monitoring->httpChecks($this->instanceId)[0]->id;
        $response = $this->post($browser, "/http-checks/{$id}/interval", ['interval' => '90']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('<p>Enter an interval of 1 to 60 minutes.</p>', (string) $response->getBody());
        $this->assertSame([['https://www.example.com/', 10]], $this->checks());
    }

    public function testFormsNeedTheCsrfTokenAndNoGetChangesState(): void
    {
        $browser = $this->signedInBrowser();
        $check = $this->environment->monitoring->addHttpCheck($this->instanceId, 'https://www.example.com/', '')->check;

        $this->assertSame(400, $browser->post($this->page() . '/http-checks', ['url' => 'https://api.example.com/'])->getStatusCode());
        $this->assertSame(400, $browser->post($this->page() . "/http-checks/{$check->id}/interval", ['interval' => '5'])->getStatusCode());
        $this->assertSame(400, $browser->post($this->page() . "/http-checks/{$check->id}/remove", [])->getStatusCode());
        $this->assertSame(405, $browser->get($this->page() . "/http-checks/{$check->id}/remove")->getStatusCode());
        $this->assertSame([['https://www.example.com/', 10]], $this->checks());
    }

    public function testAWarningAboveTheBudgetShowsOnceAfterTheRedirect(): void
    {
        $browser = $this->signedInBrowser();
        $this->environment->monitoring->addHttpCheck($this->instanceId, 'https://a.example.com/', '1');
        $this->environment->monitoring->addHttpCheck($this->instanceId, 'https://b.example.com/', '1');

        $response = $this->post($browser, '/http-checks', ['url' => 'https://c.example.com/', 'interval' => '1']);

        $this->assertSame(303, $response->getStatusCode());
        $this->assertCount(3, $this->checks(), 'Saved.');
        $warning = '<p>Saved, but all web app checks together now send about 275 MB a month, above the 200 MB suggested for them. An interval of 6 minutes or more would stay within it.</p>';
        $this->assertStringContainsString($warning, (string) $browser->get($this->page())->getBody());
        $this->assertStringNotContainsString($warning, (string) $browser->get($this->page())->getBody(), 'Only once.');
    }

    public function testTheFormGoesAwayAtTheLimit(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->environment->monitoring->addHttpCheck($this->instanceId, "https://app{$i}.example.com/", '60');
        }

        $body = (string) $this->signedInBrowser()->get($this->page())->getBody();

        $this->assertStringNotContainsString('id="url"', $body);
        $this->assertStringContainsString('This instance has the most web app checks allowed (10). Remove one to add another.', $body);
    }

    public function testResultsShowOnTheInstancePageAndTheDashboard(): void
    {
        $monitoring = $this->environment->monitoring;
        $this->environment->httpProbe->answer('https://down.example.com/', HttpProbeFailureKind::Refused);
        $monitoring->addHttpCheck($this->instanceId, 'https://up.example.com/', '');
        $monitoring->runDueHttpChecks();
        $browser = $this->signedInBrowser();

        $dashboard = (string) $browser->get('/admin')->getBody();
        $this->assertMatchesRegularExpression('#<td>Pass \(1\)<span class="info">.*?>Web app https://up.example.com/: Pass. Answered 200 in 42 ms.</span></span></td></tr>#', $dashboard);

        $monitoring->addHttpCheck($this->instanceId, 'https://down.example.com/', '');
        $monitoring->runDueHttpChecks();
        $this->environment->clock->advance(30);

        $page = (string) $browser->get($this->page())->getBody();
        $this->assertMatchesRegularExpression('#<tr><td>https://down.example.com/</td><td>Fail<span class="info">.*?>down.example.com refused the connection on port 443. Tried twice, 5 seconds apart.</span></span></td><td>30 s ago</td>#', $page);
        $dashboard = (string) $browser->get('/admin')->getBody();
        $this->assertMatchesRegularExpression('#<td>Fail<span class="info">.*?>Web app https://down.example.com/: Fail. down.example.com refused#', $dashboard);
    }
}
