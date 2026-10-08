<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Notifications;

use Maguari\Server\Notifications\Email;
use Maguari\Server\Notifications\SendFailure;
use Maguari\Server\Notifications\SmtpEncryption;
use Maguari\Server\Notifications\SmtpMailer;
use Maguari\Server\Notifications\SmtpSettings;
use Maguari\Server\Notifications\SmtpStage;
use Maguari\Server\Tests\Support\TestCertificateAuthority;
use PHPUnit\Framework\TestCase;

/**
 * PHPMailer against a scripted local SMTP server (tests/fixtures/smtp-server.php),
 * with certificates made at test time.
 */
final class SmtpMailerTest extends TestCase
{
    private const PASSWORD = 'abcd efgh ijkl mnop';

    private string $directory;
    private TestCertificateAuthority $authority;
    private string $caFile;
    private string $certificateFile;
    private string $logFile;
    /** @var list<resource> */
    private array $servers = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/maguari-smtp-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->authority = new TestCertificateAuthority($this->directory);
        $this->caFile = $this->directory . '/ca.pem';
        file_put_contents($this->caFile, $this->authority->certificatePem());
        $this->certificateFile = $this->directory . '/server.pem';
        file_put_contents($this->certificateFile, $this->authority->serverCertificate(['localhost'], 60)['pem']);
        $this->logFile = $this->directory . '/received.log';
        touch($this->logFile);
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            proc_terminate($server);
            proc_close($server);
        }

        array_map('unlink', glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    /**
     * @param array<string, mixed> $config see the fixture
     * @return int the port
     */
    private function server(string $mode, array $config = []): int
    {
        $config += ['mode' => $mode, 'certificate' => $this->certificateFile, 'log' => $this->logFile];
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/fixtures/smtp-server.php', json_encode($config, JSON_THROW_ON_ERROR)], [1 => ['pipe', 'w']], $pipes);
        $this->servers[] = $process;

        return (int) fgets($pipes[1]);
    }

    private static function settings(int $port, SmtpEncryption $encryption, string $username = 'alerts@example.com'): SmtpSettings
    {
        return new SmtpSettings('localhost', $port, $encryption, $username, $username === '' ? '' : self::PASSWORD, 'alerts@example.com');
    }

    private function send(SmtpSettings $settings, int $timeoutSeconds = 5): void
    {
        $email = new Email('jane@example.com', 'Maguari test email', "This is a test email from Maguari, sent by Zoë.\n\nSecond paragraph.\n");
        (new SmtpMailer($timeoutSeconds, ['cafile' => $this->caFile]))->send($settings, $email, 'maguari.example.com');
    }

    private function failure(SmtpSettings $settings, int $timeoutSeconds = 5): SendFailure
    {
        try {
            $this->send($settings, $timeoutSeconds);
        } catch (SendFailure $failure) {
            return $failure;
        }

        $this->fail('Expected SendFailure.');
    }

    /**
     * @return list<string>
     */
    private function received(): array
    {
        return file($this->logFile, FILE_IGNORE_NEW_LINES) ?: [];
    }

    private function assertPasswordNeverSentInPlainText(): void
    {
        foreach ($this->received() as $line) {
            if (str_starts_with($line, '[plain] ')) {
                $this->assertStringNotContainsString(self::PASSWORD, $line);
                $this->assertStringNotContainsString('CREDENTIALS', $line);
            }
        }
    }

    public function testSendsOverStartTls(): void
    {
        $this->send(self::settings($this->server('starttls'), SmtpEncryption::StartTls));
        $received = $this->received();

        $this->assertSame('[plain] EHLO maguari.example.com', $received[0]);
        $this->assertSame('[plain] STARTTLS', $received[1]);
        $this->assertSame('[tls] EHLO maguari.example.com', $received[2]);
        $this->assertContains('[tls] AUTH PLAIN', $received);
        $this->assertContains('[tls] CREDENTIALS alerts@example.com ' . self::PASSWORD, $received);
        $this->assertContains('[tls] MAIL FROM:<alerts@example.com>', $received);
        $this->assertContains('[tls] RCPT TO:<jane@example.com>', $received);
        $this->assertContains('[tls] | From: Maguari <alerts@example.com>', $received);
        $this->assertContains('[tls] | To: jane@example.com', $received);
        $this->assertContains('[tls] | Subject: Maguari test email', $received);
        $this->assertContains('[tls] | X-Mailer: Maguari', $received);
        $this->assertContains('[tls] | Content-Type: text/plain; charset=utf-8', $received);
        $body = array_slice($received, array_search('[tls] | ', $received, true) + 1, 3);
        $this->assertSame(['[tls] | This is a test email from Maguari, sent by Zo=C3=AB.', '[tls] | ', '[tls] | Second paragraph.'], $body);
        $this->assertMatchesRegularExpression('/^\[tls\] \| Message-ID: <[^@]+@maguari\.example\.com>$/m', implode("\n", $received));
        $this->assertPasswordNeverSentInPlainText();
    }

    public function testSendsOverImplicitTls(): void
    {
        $this->send(self::settings($this->server('implicit'), SmtpEncryption::ImplicitTls));
        $received = $this->received();

        $this->assertContains('[tls] CREDENTIALS alerts@example.com ' . self::PASSWORD, $received);
        $this->assertContains('[tls] | Subject: Maguari test email', $received);
        $this->assertSame([], array_filter($received, static fn (string $line): bool => str_starts_with($line, '[plain] ')));
    }

    public function testSendsUnencryptedOnlyWhenTheSettingsSaySo(): void
    {
        $this->send(self::settings($this->server('plain'), SmtpEncryption::None, ''));
        $received = $this->received();

        $this->assertNotContains('[plain] STARTTLS', $received);
        $this->assertNotContains('[plain] AUTH PLAIN', $received);
        $this->assertContains('[plain] | Subject: Maguari test email', $received);
    }

    public function testRefusesAServerWithoutStartTls(): void
    {
        $failure = $this->failure(self::settings($this->server('starttls', ['offer_tls' => false, 'replies' => ['starttls' => '502 5.5.1 Unrecognized command']]), SmtpEncryption::StartTls));

        $this->assertSame(SmtpStage::StartTls, $failure->stage);
        $this->assertSame(502, $failure->replyCode);
        $this->assertSame('STARTTLS: 502 5.5.1 Unrecognized command', $failure->detail);
        $this->assertNotContains('[plain] AUTH PLAIN', $this->received());
        $this->assertNotContains('[plain] MAIL FROM:<alerts@example.com>', $this->received());
        $this->assertPasswordNeverSentInPlainText();
    }

    public function testRefusesAnUntrustedCertificateOnStartTls(): void
    {
        file_put_contents($this->certificateFile, $this->authority->serverCertificate(['localhost'], 60, true)['pem']);

        $failure = $this->failure(self::settings($this->server('starttls'), SmtpEncryption::StartTls));

        $this->assertSame(SmtpStage::StartTls, $failure->stage);
        $this->assertNull($failure->replyCode);
        $this->assertPasswordNeverSentInPlainText();
        $this->assertNotContains('[tls] AUTH PLAIN', $this->received());
    }

    public function testRefusesACertificateForAnotherNameOnImplicitTls(): void
    {
        file_put_contents($this->certificateFile, $this->authority->serverCertificate(['smtp.example.com'], 60)['pem']);

        $failure = $this->failure(self::settings($this->server('implicit'), SmtpEncryption::ImplicitTls));

        $this->assertSame(SmtpStage::Connect, $failure->stage);
        $this->assertSame([], $this->received());
    }

    public function testAuthenticationRefusedKeepsTheServersTextOutOfTheDetail(): void
    {
        $port = $this->server('starttls', ['replies' => ['auth' => '535 5.7.8 Username and Password not accepted for alerts@example.com']]);

        $failure = $this->failure(self::settings($port, SmtpEncryption::StartTls));

        $this->assertSame(SmtpStage::Authenticate, $failure->stage);
        $this->assertSame(535, $failure->replyCode);
        $this->assertSame('AUTH: reply 535', $failure->detail);
    }

    /**
     * @return array<string, array{string, string, SmtpStage, int}>
     */
    public static function refusals(): array
    {
        return [
            'sender needs sign-in' => ['mail', '530 5.7.0 Authentication required', SmtpStage::Sender, 530],
            'sender' => ['mail', '553 5.7.1 Sender address rejected', SmtpStage::Sender, 553],
            'recipient' => ['rcpt', '550 5.1.1 No such user', SmtpStage::Recipient, 550],
            'message' => ['data', '554 5.7.0 Message rejected as spam', SmtpStage::Message, 554],
        ];
    }

    /**
     * @dataProvider refusals
     */
    public function testRecordsTheStageAndReplyOfARefusal(string $key, string $reply, SmtpStage $stage, int $code): void
    {
        $failure = $this->failure(self::settings($this->server('starttls', ['replies' => [$key => $reply]]), SmtpEncryption::StartTls));

        $this->assertSame($stage, $failure->stage);
        $this->assertSame($code, $failure->replyCode);
        $this->assertSame($stage->value . ': ' . $reply, $failure->detail);
    }

    /**
     * PHPMailer sends QUIT after a refused greeting, inside its connect(), so
     * the reply code is gone by the time the stage is recorded.
     */
    public function testARefusedGreetingIsAConnectFailure(): void
    {
        $failure = $this->failure(self::settings($this->server('starttls', ['replies' => ['greeting' => '554 5.3.2 Not accepting mail']]), SmtpEncryption::StartTls));

        $this->assertSame(SmtpStage::Connect, $failure->stage);
    }

    public function testGivesUpOnAServerThatNeverAnswers(): void
    {
        $started = microtime(true);
        $failure = $this->failure(self::settings($this->server('silent'), SmtpEncryption::StartTls), 1);

        $this->assertSame(SmtpStage::Connect, $failure->stage);
        $this->assertNull($failure->replyCode);
        $this->assertLessThan(5.0, microtime(true) - $started);
    }

    public function testNothingListening(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $failure = $this->failure(self::settings((int) substr($name, strrpos($name, ':') + 1), SmtpEncryption::StartTls));

        $this->assertSame(SmtpStage::Connect, $failure->stage);
        $this->assertNull($failure->replyCode);
        $this->assertStringStartsWith('connect: ', $failure->detail);
    }

    public function testTheDetailNeverHoldsThePassword(): void
    {
        $port = $this->server('starttls', ['replies' => ['auth' => '535 5.7.8 Bad credentials ' . self::PASSWORD]]);

        $this->assertStringNotContainsString(self::PASSWORD, $this->failure(self::settings($port, SmtpEncryption::StartTls))->detail);
    }
}
