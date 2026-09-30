<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Http;

use Maguari\Server\Http\Session;
use Maguari\Server\Tests\Support\Browser;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class LoginFlowTest extends TestCase
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

    private function logIn(Browser $browser, string $email, string $password): ResponseInterface
    {
        $form = $browser->get('/auth/login');

        return $browser->post('/auth/login', Browser::csrfFields($form) + ['email' => $email, 'password' => $password]);
    }

    private function signedInBrowser(): Browser
    {
        $browser = $this->environment->browser();
        $this->assertSame(303, $this->logIn($browser, TestEnvironment::ADMINISTRATOR_EMAIL, TestEnvironment::ADMINISTRATOR_PASSWORD)->getStatusCode());

        return $browser;
    }

    public function testLogInOpensASessionWithANewId(): void
    {
        $browser = $this->environment->browser();
        $form = $browser->get('/auth/login');
        $sessionBeforeLogin = $browser->cookie(Session::COOKIE_NAME);

        $response = $browser->post('/auth/login', Browser::csrfFields($form) + [
            'email' => 'Jane@Example.com',
            'password' => TestEnvironment::ADMINISTRATOR_PASSWORD,
        ]);

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/admin', $response->getHeaderLine('Location'));
        $this->assertNotNull($sessionBeforeLogin);
        $this->assertNotSame($sessionBeforeLogin, $browser->cookie(Session::COOKIE_NAME));
        $this->assertStringContainsString('Signed in as Jane Doe', (string) $browser->get('/admin')->getBody());
        $this->assertSame('/admin', $browser->get('/auth/login')->getHeaderLine('Location'));
    }

    public function testSessionCookieFlags(): void
    {
        $cookie = $this->environment->browser()->get('/auth/login')->getHeaderLine('Set-Cookie');

        $this->assertMatchesRegularExpression('/^maguari_session=[A-Za-z0-9,-]{22,}; Path=\/; HttpOnly; Secure; SameSite=Lax$/', $cookie);
    }

    public function testUnknownSessionIdIsNotAdopted(): void
    {
        $browser = $this->environment->browser();
        $browser->setCookie(Session::COOKIE_NAME, 'attackerchosenid1234567890');
        $browser->get('/auth/login');

        $this->assertNotSame('attackerchosenid1234567890', $browser->cookie(Session::COOKIE_NAME));
    }

    public function testWrongPasswordAndUnknownEmailGetTheSameAnswer(): void
    {
        $wrongPassword = $this->logIn($this->environment->browser(), TestEnvironment::ADMINISTRATOR_EMAIL, 'wrong password');
        $unknownEmail = $this->logIn($this->environment->browser(), 'nobody@example.com', TestEnvironment::ADMINISTRATOR_PASSWORD);

        foreach ([$wrongPassword, $unknownEmail] as $response) {
            $this->assertSame(422, $response->getStatusCode());
            $this->assertStringContainsString('Incorrect email or password.', (string) $response->getBody());
        }
    }

    public function testLoginWithoutAValidCsrfTokenIsRejected(): void
    {
        $response = $this->environment->browser()->post('/auth/login', [
            'email' => TestEnvironment::ADMINISTRATOR_EMAIL,
            'password' => TestEnvironment::ADMINISTRATOR_PASSWORD,
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testIdleTimeout(): void
    {
        $browser = $this->signedInBrowser();

        $this->environment->clock->advance(Session::IDLE_TIMEOUT_SECONDS - 1);
        $this->assertSame(200, $browser->get('/admin')->getStatusCode());

        $this->environment->clock->advance(Session::IDLE_TIMEOUT_SECONDS);
        $response = $browser->get('/admin');

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/auth/login', $response->getHeaderLine('Location'));
    }

    public function testAbsoluteTimeout(): void
    {
        $browser = $this->signedInBrowser();

        // Active every hour, so the idle timeout never applies.
        for ($hour = 1; $hour < 12; $hour++) {
            $this->environment->clock->advance(3600);
            $this->assertSame(200, $browser->get('/admin')->getStatusCode(), "hour {$hour}");
        }

        $this->environment->clock->advance(3600);
        $this->assertSame(303, $browser->get('/admin')->getStatusCode());
    }

    public function testSignOutEndsTheSession(): void
    {
        $browser = $this->signedInBrowser();
        $sessionId = $browser->cookie(Session::COOKIE_NAME);
        $admin = $browser->get('/admin');

        $response = $browser->post('/admin/logout', Browser::csrfFields($admin));

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/auth/login', $response->getHeaderLine('Location'));
        $this->assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));
        $this->assertNull($browser->cookie(Session::COOKIE_NAME));

        // The old session ID no longer signs anyone in.
        $replay = $this->environment->browser();
        $replay->setCookie(Session::COOKIE_NAME, (string) $sessionId);
        $this->assertSame(303, $replay->get('/admin')->getStatusCode());
    }

    public function testSignOutRequiresTheCsrfToken(): void
    {
        $browser = $this->signedInBrowser();

        $this->assertSame(400, $browser->post('/admin/logout', [])->getStatusCode());
        $this->assertSame(200, $browser->get('/admin')->getStatusCode());
    }

    public function testFailedLoginsAreRateLimitedPerIp(): void
    {
        $browser = $this->environment->browser();
        $csrf = Browser::csrfFields($browser->get('/auth/login'));

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(422, $browser->post('/auth/login', $csrf + ['email' => TestEnvironment::ADMINISTRATOR_EMAIL, 'password' => 'guess ' . $i])->getStatusCode());
        }

        $correct = $csrf + ['email' => TestEnvironment::ADMINISTRATOR_EMAIL, 'password' => TestEnvironment::ADMINISTRATOR_PASSWORD];
        $this->assertSame(429, $browser->post('/auth/login', $correct)->getStatusCode());
        $this->assertSame(303, $this->logIn($this->environment->browser('192.0.2.99'), TestEnvironment::ADMINISTRATOR_EMAIL, TestEnvironment::ADMINISTRATOR_PASSWORD)->getStatusCode());

        $this->environment->clock->advance(900);
        $this->assertSame(303, $browser->post('/auth/login', $correct)->getStatusCode());
    }
}
