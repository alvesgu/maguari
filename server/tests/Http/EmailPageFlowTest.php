<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Http;

use Maguari\Server\Kernel\Secrets\SecretBox;
use Maguari\Server\Notifications\NotificationsApi;
use Maguari\Server\Notifications\SendFailure;
use Maguari\Server\Notifications\SmtpStage;
use Maguari\Server\Notifications\SmtpSettingsInput;
use Maguari\Server\Tests\Support\Browser;
use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The Email page: SMTP settings and the seed file's [smtp] section (design
 * sections 11.5.1 and 13).
 */
final class EmailPageFlowTest extends TestCase
{
    private const PASSWORD = 'abcd efgh ijkl mnop';
    private const SEED_ADMINISTRATOR = "[administrator]\nname = \"Jane Doe\"\nemail = \"jane@example.com\"\n\n";

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

    /**
     * @param array<string, string> $fields
     */
    private function save(Browser $browser, array $fields): ResponseInterface
    {
        return $browser->post('/admin/email', Browser::csrfFields($browser->get('/admin/email')) + $fields + [
            'host' => 'smtp.gmail.com',
            'port' => '587',
            'username' => 'alerts@example.com',
            'password' => self::PASSWORD,
            'from_address' => 'alerts@example.com',
        ]);
    }

    private function importSeed(Browser $browser): ResponseInterface
    {
        return $browser->post('/admin/email/import-seed', Browser::csrfFields($browser->get('/admin/email')));
    }

    public function testTheDashboardLinksToThePage(): void
    {
        $this->assertStringContainsString('<a href="/admin/email">Email</a>', (string) $this->signedInBrowser()->get('/admin')->getBody());
    }

    public function testShowsThatEmailIsNotSetUpAndTheRecipient(): void
    {
        $response = $this->signedInBrowser()->get('/admin/email');
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('<h1>Email</h1>', $body);
        $this->assertStringContainsString('<p>Email is not set up yet.</p>', $body);
        $this->assertStringContainsString('<p>Emails go to jane@example.com, the address you signed in with.</p>', $body);
        $this->assertStringContainsString('name="port" type="text" inputmode="numeric" value="587"', $body);
        $this->assertStringNotContainsString('import-seed', $body);
    }

    public function testSavesAndShowsTheSettingsWithoutThePassword(): void
    {
        $browser = $this->signedInBrowser();
        $response = $this->save($browser, ['host' => 'SMTP.Gmail.com']);

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/admin/email', $response->getHeaderLine('Location'));

        $body = (string) $browser->get('/admin/email')->getBody();

        $this->assertStringContainsString('<tr><th>Host</th><td>smtp.gmail.com</td></tr>', $body);
        $this->assertStringContainsString('<tr><th>Encryption</th><td>STARTTLS</td></tr>', $body);
        $this->assertStringContainsString('<tr><th>Password</th><td>Stored (encrypted)</td></tr>', $body);
        $this->assertStringContainsString('Password (leave empty to keep the stored one, unless you change the username)', $body);
        $this->assertStringContainsString('<input id="password" name="password" type="password" maxlength="1024" autocomplete="new-password">', $body);
        $this->assertStringNotContainsString(self::PASSWORD, $body);
    }

    public function testShowsImplicitTlsAndNoSignIn(): void
    {
        $browser = $this->signedInBrowser();
        $this->save($browser, ['port' => '465', 'username' => '', 'password' => '']);
        $body = (string) $browser->get('/admin/email')->getBody();

        $this->assertStringContainsString('<tr><th>Encryption</th><td>TLS from the start (port 465)</td></tr>', $body);
        $this->assertStringContainsString('<tr><th>Username</th><td>None (no sign-in)</td></tr>', $body);
        $this->assertStringContainsString('<tr><th>Password</th><td>None</td></tr>', $body);
    }

    public function testRefusedSettingsShowTheSentencesAndWhatWasTypedButNeverThePassword(): void
    {
        $response = $this->save($this->signedInBrowser(), ['host' => 'smtp.gmail.com:587', 'port' => '25']);
        $body = (string) $response->getBody();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Enter a host name such as smtp.gmail.com, without a scheme, port or path.', $body);
        $this->assertStringContainsString('Compute Engine blocks outbound port 25. Use port 587.', $body);
        $this->assertStringContainsString('value="smtp.gmail.com:587"', $body);
        $this->assertStringContainsString('value="25"', $body);
        $this->assertStringNotContainsString(self::PASSWORD, $body);
        $this->assertNull($this->environment->notifications->smtpSettings());
    }

    public function testShowsAPasswordThisKeyCannotDecrypt(): void
    {
        (new NotificationsApi($this->environment->database, $this->environment->clock, new SecretBox(random_bytes(32))))
            ->saveSmtpSettings(new SmtpSettingsInput('smtp.gmail.com', '587', 'alerts@example.com', self::PASSWORD, 'alerts@example.com'));

        $this->assertStringContainsString(htmlspecialchars(NotificationsApi::PASSWORD_UNREADABLE, ENT_QUOTES), (string) $this->signedInBrowser()->get('/admin/email')->getBody());
    }

    public function testNeedsASignedInAdministrator(): void
    {
        $stranger = $this->environment->browser('192.0.2.20');

        $this->assertSame('/auth/login', $stranger->get('/admin/email')->getHeaderLine('Location'));
        $response = $stranger->post('/admin/email', Browser::csrfFields($stranger->get('/auth/login')) + ['host' => 'smtp.gmail.com']);
        $this->assertSame('/auth/login', $response->getHeaderLine('Location'));
        $this->assertNull($this->environment->notifications->smtpSettings());
    }

    public function testFormsNeedTheCsrfToken(): void
    {
        $browser = $this->signedInBrowser();
        $this->environment->writeSeedConfig(self::SEED_ADMINISTRATOR . "[smtp]\nhost = \"localhost\"\nfrom = \"maguari@example.test\"\n");

        $this->assertSame(400, $browser->post('/admin/email', ['host' => 'smtp.gmail.com', 'from_address' => 'a@example.com'])->getStatusCode());
        $this->assertSame(400, $browser->post('/admin/email/import-seed', [])->getStatusCode());
        $this->assertNull($this->environment->notifications->smtpSettings());
    }

    public function testNoStateChangingGet(): void
    {
        $browser = $this->signedInBrowser();
        $this->save($browser, []);

        $this->assertSame(405, $browser->get('/admin/email/import-seed')->getStatusCode());
        $this->assertSame(405, $browser->get('/admin/email/test')->getStatusCode());
        $this->assertSame([], $this->environment->mailer->sent);
    }

    public function testImportsTheSeedFileOnce(): void
    {
        $this->environment->writeSeedConfig(self::SEED_ADMINISTRATOR
            . "[smtp]\nhost = \"smtp.gmail.com\"\nport = \"587\"\nusername = \"alerts@example.com\"\npassword = \"" . self::PASSWORD . "\"\nfrom = \"alerts@example.com\"\n");
        $browser = $this->signedInBrowser();
        $page = (string) $browser->get('/admin/email')->getBody();

        $this->assertStringContainsString('<p>The seed file has SMTP settings for smtp.gmail.com. <button type="submit">Use the SMTP settings from the seed file</button></p>', $page);
        $this->assertStringNotContainsString(self::PASSWORD, $page);

        $response = $this->importSeed($browser);

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/admin/email', $response->getHeaderLine('Location'));
        $settings = $this->environment->notifications->smtpSettings();
        $this->assertNotNull($settings);
        $this->assertSame('smtp.gmail.com', $settings->host);
        $this->assertTrue($settings->passwordReadable);

        // Once settings are stored, the web app owns them.
        $this->save($browser, ['host' => 'smtp-relay.gmail.com']);
        $this->assertStringNotContainsString('import-seed', (string) $browser->get('/admin/email')->getBody());
        $this->assertSame(303, $this->importSeed($browser)->getStatusCode());
        $this->assertSame('smtp-relay.gmail.com', $this->environment->notifications->smtpSettings()?->host);
    }

    public function testASeedSectionThatBreaksTheRulesIsNotUsed(): void
    {
        $this->environment->writeSeedConfig(self::SEED_ADMINISTRATOR . "[smtp]\nhost = \"smtp.gmail.com\"\nport = \"25\"\nusername = \"alerts@example.com\"\n");
        $response = $this->importSeed($this->signedInBrowser());
        $body = (string) $response->getBody();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString("<p>The seed file's SMTP settings were not used:</p>", $body);
        $this->assertStringContainsString('<li>Compute Engine blocks outbound port 25. Use port 587.</li>', $body);
        $this->assertStringContainsString('<li>Enter the password for this username.</li>', $body);
        $this->assertNull($this->environment->notifications->smtpSettings());
    }

    public function testAnUnreadableSeedFileIsNamed(): void
    {
        $this->environment->writeSeedConfig("[administrator]\nname = \"Jane Doe\"\n");
        $body = (string) $this->signedInBrowser()->get('/admin/email')->getBody();

        $this->assertStringContainsString('<p>The seed file could not be read: Seed config key &quot;administrator.email&quot; is missing.</p>', $body);
    }

    public function testWithoutAnSmtpSectionThereIsNoImport(): void
    {
        $this->environment->writeSeedConfig(self::SEED_ADMINISTRATOR);
        $browser = $this->signedInBrowser();

        $this->assertStringNotContainsString('import-seed', (string) $browser->get('/admin/email')->getBody());
        $this->assertSame(303, $this->importSeed($browser)->getStatusCode());
        $this->assertNull($this->environment->notifications->smtpSettings());
    }

    private function sendTest(Browser $browser): ResponseInterface
    {
        return $browser->post('/admin/email/test', Browser::csrfFields($browser->get('/admin/email')));
    }

    public function testOffersTheTestEmailOnlyOnceSettingsAreSaved(): void
    {
        $browser = $this->signedInBrowser();

        $this->assertStringContainsString('<p>Save the settings first, then send a test email.</p>', (string) $browser->get('/admin/email')->getBody());
        $this->save($browser, []);
        $body = (string) $browser->get('/admin/email')->getBody();
        $this->assertStringContainsString('<form method="post" action="/admin/email/test">', $body);
        $this->assertStringContainsString('<button type="submit">Send test email</button> Sends a test email to jane@example.com with the saved settings', $body);
    }

    public function testSendsTheTestEmailToTheSignedInAdministrator(): void
    {
        $browser = $this->signedInBrowser();
        $this->save($browser, []);
        $response = $this->sendTest($browser);
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<p>Sent a test email to jane@example.com through smtp.gmail.com at ', $body);
        $this->assertCount(1, $this->environment->mailer->sent);
        $this->assertSame('jane@example.com', $this->environment->mailer->sent[0]['email']->recipient);
        $this->assertStringContainsString('sent at ', $this->environment->mailer->sent[0]['email']->body);
        $this->assertStringNotContainsString(self::PASSWORD, $body);
    }

    public function testTheTestEmailNeedsSavedSettings(): void
    {
        $response = $this->sendTest($this->signedInBrowser());

        $this->assertSame(409, $response->getStatusCode());
        $this->assertStringContainsString('<p><strong>Not sent:</strong> Email is not set up yet. Fill in the SMTP settings and save them first.</p>', (string) $response->getBody());
        $this->assertSame([], $this->environment->mailer->sent);
    }

    public function testAFailedTestEmailShowsItsSentence(): void
    {
        $browser = $this->signedInBrowser();
        $this->save($browser, []);
        $this->environment->mailer->failure = new SendFailure(SmtpStage::Recipient, 550, 'RCPT TO: 550 5.1.1 No such user');
        $response = $this->sendTest($browser);
        $body = (string) $response->getBody();

        $this->assertSame(502, $response->getStatusCode());
        $this->assertStringContainsString('<p><strong>Not sent:</strong> smtp.gmail.com refused the recipient address jane@example.com (reply 550).</p>', $body);
        $this->assertStringNotContainsString('No such user', $body);
    }

    public function testTheTestEmailNeedsTheCsrfTokenAndASession(): void
    {
        $browser = $this->signedInBrowser();
        $this->save($browser, []);
        $stranger = $this->environment->browser('192.0.2.20');

        $this->assertSame(400, $browser->post('/admin/email/test', [])->getStatusCode());
        $this->assertSame('/auth/login', $stranger->post('/admin/email/test', Browser::csrfFields($stranger->get('/auth/login')))->getHeaderLine('Location'));
        $this->assertSame([], $this->environment->mailer->sent);
    }
}
