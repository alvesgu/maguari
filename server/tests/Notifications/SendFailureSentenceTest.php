<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Notifications;

use Maguari\Server\Notifications\SendFailure;
use Maguari\Server\Notifications\SendFailureSentence;
use Maguari\Server\Notifications\SmtpEncryption;
use Maguari\Server\Notifications\SmtpSettings;
use Maguari\Server\Notifications\SmtpStage;
use PHPUnit\Framework\TestCase;

final class SendFailureSentenceTest extends TestCase
{
    private static function sentence(?SmtpStage $stage, ?int $code, int $port = 587): string
    {
        $settings = new SmtpSettings('smtp.gmail.com', $port, SmtpEncryption::for('smtp.gmail.com', $port), 'alerts@example.com', 'secret', 'alerts@example.com');

        return SendFailureSentence::for(new SendFailure($stage, $code, 'detail'), $settings, 'jane@example.com');
    }

    public function testConnect(): void
    {
        $this->assertSame(
            'Could not connect to smtp.gmail.com on port 587, or it did not answer. Check the host and port, and that this server can reach them.',
            self::sentence(SmtpStage::Connect, null),
        );
    }

    public function testConnectWithImplicitTlsMentionsTheCertificate(): void
    {
        $this->assertSame(
            'Could not connect to smtp.gmail.com on port 465 with TLS, or it did not answer. Check the host and port, '
                . 'that this server can reach them and that the host name matches the server\'s certificate.',
            self::sentence(SmtpStage::Connect, null, 465),
        );
    }

    public function testStartTls(): void
    {
        $this->assertSame(
            'smtp.gmail.com did not set up an encrypted connection, so no password or message was sent. '
                . 'Check the port (587 uses STARTTLS, 465 uses TLS from the start) and that the host name matches the server\'s certificate.',
            self::sentence(SmtpStage::StartTls, 502),
        );
    }

    public function testAuthenticate(): void
    {
        $this->assertSame(
            'smtp.gmail.com did not accept the username and password (reply 535). For Gmail, use an app password, not the account password.',
            self::sentence(SmtpStage::Authenticate, 535),
        );
        $this->assertSame(
            'smtp.gmail.com did not accept the username and password. For Gmail, use an app password, not the account password.',
            self::sentence(SmtpStage::Authenticate, null),
        );
    }

    public function testSender(): void
    {
        $this->assertSame('smtp.gmail.com requires a username and password.', self::sentence(SmtpStage::Sender, 530));
        $this->assertSame(
            'smtp.gmail.com refused the sender address alerts@example.com (reply 553). Use an address this account is allowed to send from.',
            self::sentence(SmtpStage::Sender, 553),
        );
    }

    public function testRecipientAndMessage(): void
    {
        $this->assertSame('smtp.gmail.com refused the recipient address jane@example.com (reply 550).', self::sentence(SmtpStage::Recipient, 550));
        $this->assertSame('smtp.gmail.com refused the message (reply 554).', self::sentence(SmtpStage::Message, 554));
        $this->assertSame('smtp.gmail.com refused the message.', self::sentence(SmtpStage::Message, null));
    }

    public function testAnythingElse(): void
    {
        $this->assertSame('The test email could not be sent. The details are in the server\'s error log.', self::sentence(null, null));
    }

    public function testReplyCodes(): void
    {
        $this->assertSame(535, SendFailure::replyCode("535 5.7.8 Bad\r\n"));
        $this->assertSame(550, SendFailure::replyCode("550-5.1.1 First\r\n550 5.1.1 Last\r\n"));
        $this->assertNull(SendFailure::replyCode("220 Ready\r\n"));
        $this->assertNull(SendFailure::replyCode(''));
        $this->assertSame('550-5.1.1 First', SendFailure::firstLine("550-5.1.1 First\r\n550 5.1.1 Last\r\n"));
    }
}
