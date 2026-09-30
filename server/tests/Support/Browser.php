<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Slim\App as SlimApp;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Sends requests to the app and keeps its cookies between them, like a browser.
 */
final class Browser
{
    /** @var array<string, string> */
    private array $cookies = [];

    public function __construct(
        private readonly SlimApp $app,
        private readonly string $ip,
    ) {
    }

    public function get(string $uri): ResponseInterface
    {
        return $this->request('GET', $uri);
    }

    /**
     * @param array<string, string> $form
     */
    public function post(string $uri, array $form): ResponseInterface
    {
        return $this->request('POST', $uri, $form);
    }

    /**
     * @param array<string, string>|null $form
     */
    public function request(string $method, string $uri, ?array $form = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $uri, ['REMOTE_ADDR' => $this->ip])
            ->withCookieParams($this->cookies);

        if ($form !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withParsedBody($form);
        }

        $response = $this->app->handle($request);

        foreach ($response->getHeader('Set-Cookie') as $header) {
            [$pair] = explode(';', $header, 2);
            [$name, $value] = explode('=', $pair, 2);

            if (str_contains($header, 'Max-Age=0')) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }

        return $response;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function setCookie(string $name, string $value): void
    {
        $this->cookies[$name] = $value;
    }

    /**
     * The hidden CSRF fields of the form in $response.
     *
     * @return array<string, string>
     */
    public static function csrfFields(ResponseInterface $response): array
    {
        preg_match_all('/<input type="hidden" name="(csrf_name|csrf_value)" value="([^"]*)">/', (string) $response->getBody(), $matches, PREG_SET_ORDER);
        $fields = [];

        foreach ($matches as [, $name, $value]) {
            $fields[$name] = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        }

        return $fields;
    }
}
