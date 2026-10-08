<?php

declare(strict_types=1);

namespace Maguari\Server\Http\Controller;

use Maguari\Server\Access\AccessApi;
use Maguari\Server\Access\Administrator;
use Maguari\Server\Access\Exception\InvalidSeedConfig;
use Maguari\Server\Access\SeedSmtp;
use Maguari\Server\Http\View;
use Maguari\Server\Notifications\Exception\InvalidSmtpSettings;
use Maguari\Server\Notifications\NotificationsApi;
use Maguari\Server\Notifications\SmtpSettingsInput;
use Maguari\Server\Notifications\SmtpSettingsRules;
use Maguari\Server\Notifications\SmtpSettingsSummary;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The Email page (design section 13): the SMTP settings, and the seed file's
 * [smtp] section applied with a button while none are stored (design section
 * 11.5.1). The stored password is never sent to the browser.
 */
final class EmailController
{
    public function __construct(
        private readonly AccessApi $access,
        private readonly NotificationsApi $notifications,
        private readonly View $view,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->page($request, $response, self::formFrom($this->notifications->smtpSettings()), [], []);
    }

    public function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $input = new SmtpSettingsInput(
            FormInput::string($body, 'host'),
            FormInput::string($body, 'port'),
            FormInput::string($body, 'username'),
            FormInput::string($body, 'password'),
            FormInput::string($body, 'from_address'),
        );

        try {
            $this->notifications->saveSmtpSettings($input);
        } catch (InvalidSmtpSettings $invalid) {
            // What was typed comes back, except the password.
            $form = ['host' => $input->host, 'port' => $input->port, 'username' => $input->username, 'from_address' => $input->fromAddress];

            return $this->page($request, $response, $form, $invalid->errors, [], 422);
        }

        return self::backToPage($response);
    }

    /**
     * Pressing the button on a stale page, after settings were stored, just
     * shows the page again.
     */
    public function importSeed(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $seed = $this->seedSmtp();

        if ($seed === null) {
            return self::backToPage($response);
        }

        try {
            $this->notifications->importSmtpSettings(new SmtpSettingsInput($seed->host, $seed->port, $seed->username, $seed->password, $seed->from));
        } catch (InvalidSmtpSettings $invalid) {
            $form = self::formFrom($this->notifications->smtpSettings());

            return $this->page($request, $response, $form, [], array_values($invalid->errors), 422);
        }

        return self::backToPage($response);
    }

    private static function backToPage(ResponseInterface $response): ResponseInterface
    {
        return $response->withStatus(303)->withHeader('Location', '/admin/email');
    }

    private function seedSmtp(): ?SeedSmtp
    {
        try {
            return $this->access->readSeedConfig()?->smtp;
        } catch (InvalidSeedConfig) {
            return null;
        }
    }

    /**
     * @return array{host: string, port: string, username: string, from_address: string}
     */
    private static function formFrom(?SmtpSettingsSummary $settings): array
    {
        return [
            'host' => $settings->host ?? '',
            'port' => (string) ($settings->port ?? SmtpSettingsRules::DEFAULT_PORT),
            'username' => $settings->username ?? '',
            'from_address' => $settings->fromAddress ?? '',
        ];
    }

    /**
     * @param array{host: string, port: string, username: string, from_address: string} $form
     * @param array<string, string> $errors the form's sentences, by field
     * @param string[] $seedErrors why the seed file's settings were not used
     */
    private function page(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $form,
        array $errors,
        array $seedErrors,
        int $status = 200,
    ): ResponseInterface {
        $settings = $this->notifications->smtpSettings();
        $seedProblem = null;
        $seedHost = null;

        // The seed file matters only until settings are stored.
        if ($settings === null) {
            try {
                $seedHost = $this->access->readSeedConfig()?->smtp?->host;
            } catch (InvalidSeedConfig $invalid) {
                $seedProblem = $invalid->getMessage();
            }
        }

        $administrator = $request->getAttribute(Administrator::class);

        return $this->view->render($request, $response, 'email', [
            'title' => 'Email',
            'settings' => $settings,
            'recipient' => $administrator instanceof Administrator ? $administrator->email : '',
            'form' => $form,
            'errors' => $errors,
            'seedHost' => $seedHost,
            'seedProblem' => $seedProblem,
            'seedErrors' => $seedErrors,
        ], $status);
    }
}
