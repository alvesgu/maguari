<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Http;

use Maguari\Server\Http\Session;
use Maguari\Server\Tests\Support\Browser;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

final class SetupFlowTest extends TestCase
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

    /**
     * @return array<string, string>
     */
    private static function validForm(string $token): array
    {
        return [
            'token' => $token,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'correct horse battery',
            'password_confirmation' => 'correct horse battery',
        ];
    }

    public function testSetupCreatesTheAdministratorAndSignsIn(): void
    {
        $token = $this->environment->access->issueSetupToken()->token;
        $browser = $this->environment->browser();

        $form = $browser->get('/auth/setup?token=' . $token);
        $this->assertSame(200, $form->getStatusCode());
        $this->assertSame('no-referrer', $form->getHeaderLine('Referrer-Policy'));
        $this->assertSame('no-store', $form->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('name="token" value="' . $token . '"', (string) $form->getBody());
        $sessionBeforeSetup = $browser->cookie(Session::COOKIE_NAME);

        $response = $browser->post('/auth/setup', Browser::csrfFields($form) + self::validForm($token));

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/admin', $response->getHeaderLine('Location'));
        $this->assertNotSame($sessionBeforeSetup, $browser->cookie(Session::COOKIE_NAME));
        $this->assertTrue($this->environment->access->isSetupComplete());

        $admin = $browser->get('/admin');
        $this->assertSame(200, $admin->getStatusCode());
        $this->assertStringContainsString('Signed in as Jane Doe (jane@example.com)', (string) $admin->getBody());
    }

    public function testFormIsPrefilledFromTheSeedConfigFile(): void
    {
        $this->environment->writeSeedConfig("[administrator]\nname = \"Seeded <Admin>\"\nemail = \"Seeded@Example.com\"\n");
        $token = $this->environment->access->issueSetupToken()->token;

        $body = (string) $this->environment->browser()->get('/auth/setup?token=' . $token)->getBody();

        $this->assertStringContainsString('value="Seeded &lt;Admin&gt;"', $body);
        $this->assertStringContainsString('value="seeded@example.com"', $body);
    }

    public function testInvalidSeedConfigFileIsIgnored(): void
    {
        $this->environment->writeSeedConfig("[administrator]\nname = \"Jane\"\n");
        $token = $this->environment->access->issueSetupToken()->token;

        $this->assertSame(200, $this->environment->browser()->get('/auth/setup?token=' . $token)->getStatusCode());
    }

    public function testMissingUnknownAndExpiredTokensAreRejected(): void
    {
        $token = $this->environment->access->issueSetupToken()->token;
        $browser = $this->environment->browser();

        $this->assertSame(403, $browser->get('/auth/setup')->getStatusCode());
        $this->assertSame(403, $browser->get('/auth/setup?token=unknown')->getStatusCode());
        $this->assertSame(200, $browser->get('/auth/setup?token=' . $token)->getStatusCode());

        $this->environment->clock->advance(3600);
        $response = $browser->get('/auth/setup?token=' . $token);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('invalid or expired', (string) $response->getBody());
    }

    public function testExpiredTokenIsRejectedOnSubmit(): void
    {
        $token = $this->environment->access->issueSetupToken()->token;
        $browser = $this->environment->browser();
        $form = $browser->get('/auth/setup?token=' . $token);

        $this->environment->clock->advance(3600);
        $response = $browser->post('/auth/setup', Browser::csrfFields($form) + self::validForm($token));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($this->environment->access->isSetupComplete());
    }

    public function testSubmitWithoutAValidCsrfTokenIsRejected(): void
    {
        $token = $this->environment->access->issueSetupToken()->token;
        $browser = $this->environment->browser();
        $csrf = Browser::csrfFields($browser->get('/auth/setup?token=' . $token));

        $this->assertSame(400, $browser->post('/auth/setup', self::validForm($token))->getStatusCode());
        $this->assertSame(400, $browser->post('/auth/setup', ['csrf_value' => 'wrong'] + $csrf + self::validForm($token))->getStatusCode());
        // The CSRF token belongs to the session: another browser cannot use it.
        $this->assertSame(400, $this->environment->browser()->post('/auth/setup', $csrf + self::validForm($token))->getStatusCode());
        $this->assertFalse($this->environment->access->isSetupComplete());
    }

    public function testValidationErrorsShowTheFormAgainWithoutPasswords(): void
    {
        $token = $this->environment->access->issueSetupToken()->token;
        $browser = $this->environment->browser();
        $form = $browser->get('/auth/setup?token=' . $token);

        $response = $browser->post('/auth/setup', ['password' => 'too-short', 'password_confirmation' => 'too-short']
            + Browser::csrfFields($form) + self::validForm($token));
        $body = (string) $response->getBody();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Use a password of 12 to 1024 characters.', $body);
        $this->assertStringContainsString('value="Jane Doe"', $body);
        $this->assertStringNotContainsString('too-short', $body);
        $this->assertFalse($this->environment->access->isSetupComplete());
    }

    public function testSetupIsGoneOnceComplete(): void
    {
        $token = $this->environment->access->issueSetupToken()->token;
        $browser = $this->environment->browser();
        $form = $browser->get('/auth/setup?token=' . $token);
        $browser->post('/auth/setup', Browser::csrfFields($form) + self::validForm($token));

        $this->assertSame(404, $browser->get('/auth/setup?token=' . $token)->getStatusCode());

        // A second browser that loaded the form before setup finished cannot reuse the token.
        $other = $this->environment->browser('192.0.2.20');
        $otherForm = $other->get('/auth/login');
        $response = $other->post('/auth/setup', Browser::csrfFields($otherForm) + self::validForm($token));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testFailedSubmissionsAreRateLimited(): void
    {
        $token = $this->environment->access->issueSetupToken()->token;
        $browser = $this->environment->browser();
        $csrf = Browser::csrfFields($browser->get('/auth/setup?token=' . $token));

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(403, $browser->post('/auth/setup', $csrf + self::validForm('guess-' . $i))->getStatusCode());
        }

        $response = $browser->post('/auth/setup', $csrf + self::validForm($token));

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('900', $response->getHeaderLine('Retry-After'));
        $this->assertFalse($this->environment->access->isSetupComplete());
    }
}
