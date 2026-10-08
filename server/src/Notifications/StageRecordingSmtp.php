<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications;

use PHPMailer\PHPMailer\SMTP;

/**
 * PHPMailer's SMTP class, recording the stage that failed and the server's
 * reply at that moment, so failures are told apart by stage and reply code
 * rather than by PHPMailer's English messages. PHPMailer goes on talking after
 * some failures (QUIT after a refused recipient), so the reply is kept when
 * the failure happens, not read afterwards.
 *
 * Only the public methods of SMTP are overridden, with their own signatures.
 */
final class StageRecordingSmtp extends SMTP
{
    private ?SmtpStage $failedStage = null;
    private string $failedReply = '';
    /** @var array<string, string> */
    private array $failedError = [];

    public function failedStage(): ?SmtpStage
    {
        return $this->failedStage;
    }

    /**
     * The server's reply when the stage failed ('' when it never answered).
     */
    public function failedReply(): string
    {
        return $this->failedReply;
    }

    /**
     * PHPMailer's error at that moment: error, detail, smtp_code and
     * smtp_code_ex. For a failed connection, smtp_code is the socket error
     * number, not an SMTP reply code.
     *
     * @return array<string, string>
     */
    public function failedError(): array
    {
        return $this->failedError;
    }

    public function connect($host, $port = null, $timeout = 30, $options = [])
    {
        return $this->recorded(SmtpStage::Connect, (bool) parent::connect($host, $port, $timeout, $options));
    }

    public function startTLS()
    {
        return $this->recorded(SmtpStage::StartTls, (bool) parent::startTLS());
    }

    public function authenticate($username, $password, $authtype = null, $OAuth = null)
    {
        return $this->recorded(SmtpStage::Authenticate, (bool) parent::authenticate($username, $password, $authtype, $OAuth));
    }

    public function mail($from)
    {
        return $this->recorded(SmtpStage::Sender, (bool) parent::mail($from));
    }

    public function recipient($address, $dsn = '')
    {
        return $this->recorded(SmtpStage::Recipient, (bool) parent::recipient($address, $dsn));
    }

    public function data($msg_data)
    {
        return $this->recorded(SmtpStage::Message, (bool) parent::data($msg_data));
    }

    /**
     * Keeps the first failure: later ones (QUIT on a closed connection) are
     * consequences.
     */
    private function recorded(SmtpStage $stage, bool $succeeded): bool
    {
        if (!$succeeded && $this->failedStage === null) {
            $this->failedStage = $stage;
            $this->failedReply = (string) $this->getLastReply();
            $this->failedError = array_map('strval', $this->getError());
        }

        return $succeeded;
    }
}
