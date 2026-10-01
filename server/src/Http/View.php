<?php

declare(strict_types=1);

namespace Maguari\Server\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders plain PHP templates from server/templates/. Templates escape every
 * value with $e(); $csrf holds the already escaped hidden CSRF fields.
 */
final class View
{
    private readonly string $templateDirectory;

    public function __construct(?string $templateDirectory = null)
    {
        $this->templateDirectory = $templateDirectory ?? dirname(__DIR__, 2) . '/templates';
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $template,
        array $data = [],
        int $status = 200,
    ): ResponseInterface {
        $data['csrf'] = self::csrfFields($request);
        $response->getBody()->write($this->page($template, $data));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * The template inside the page layout, for callers that have no request,
     * such as the error renderer. $csrf is not set.
     *
     * @param array<string, mixed> $data
     */
    public function page(string $template, array $data = []): string
    {
        $content = $this->renderFile($template, $data);

        return $this->renderFile('layout', ['title' => $data['title'] ?? 'Maguari', 'content' => $content]);
    }

    private static function csrfFields(ServerRequestInterface $request): string
    {
        $fields = '';

        foreach (['csrf_name', 'csrf_value'] as $key) {
            $value = $request->getAttribute($key);

            if (is_string($value)) {
                $fields .= sprintf('<input type="hidden" name="%s" value="%s">', $key, self::escape($value));
            }
        }

        return $fields;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderFile(string $template, array $data): string
    {
        $file = $this->templateDirectory . '/' . $template . '.php';
        $e = static fn (string $value): string => self::escape($value);

        ob_start();

        try {
            (static function (string $__file, array $__data, \Closure $e): void {
                extract($__data, EXTR_SKIP);
                require $__file;
            })($file, $data, $e);
        } catch (\Throwable $exception) {
            ob_end_clean();

            throw $exception;
        }

        return (string) ob_get_clean();
    }
}
