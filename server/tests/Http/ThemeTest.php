<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Http;

use Maguari\Server\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The dark theme is one external stylesheet: the Content-Security-Policy
 * blocks inline styles, so no template may use them.
 */
final class ThemeTest extends TestCase
{
    private const STYLESHEET = '/assets/maguari.css';

    public function testEveryPageLinksTheStylesheet(): void
    {
        $environment = new TestEnvironment();

        try {
            foreach (['/auth/login', '/nonexistent'] as $path) {
                $response = $environment->app()->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
                $body = (string) $response->getBody();

                $this->assertStringContainsString('<link rel="stylesheet" href="' . self::STYLESHEET . '">', $body, $path);
                $this->assertStringContainsString("default-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
                $this->assertStringNotContainsString('unsafe-inline', $response->getHeaderLine('Content-Security-Policy'));
            }
        } finally {
            $environment->cleanUp();
        }
    }

    public function testTheStylesheetIsDarkAndServedFromTheWebRoot(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public' . self::STYLESHEET);

        $this->assertStringContainsString('color-scheme: dark;', $css);
        $this->assertStringContainsString('--background: #000000;', $css);
    }

    public function testNoTemplateUsesInlineStyles(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/templates/*.php') ?: [] as $template) {
            $this->assertDoesNotMatchRegularExpression('/<style|\sstyle\s*=/i', (string) file_get_contents($template), basename($template));
        }
    }
}
