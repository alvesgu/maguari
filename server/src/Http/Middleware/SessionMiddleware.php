<?php

declare(strict_types=1);

namespace Maguari\Server\Http\Middleware;

use Maguari\Server\Http\Session;
use Maguari\Server\Kernel\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/**
 * Native PHP sessions with the cookie handled here, on the PSR-7 response, so
 * its flags are explicit and testable (design section 11.3).
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $savePath,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Applies the session settings. Only settings that differ are changed,
     * because PHP refuses to change any session setting once output has been
     * sent. Tests call this before PHPUnit prints anything.
     *
     * The save path is dedicated to Maguari. Ubuntu turns PHP's own session
     * garbage collection off and cleans only the default save paths from cron,
     * so it is turned back on here, with gc_maxlifetime matching the absolute
     * timeout.
     */
    public static function configure(string $savePath): void
    {
        $settings = [
            'use_cookies' => '0',
            'use_only_cookies' => '1',
            'use_trans_sid' => '0',
            'cache_limiter' => '',
            'use_strict_mode' => '1',
            'save_path' => $savePath,
            'gc_maxlifetime' => (string) Session::ABSOLUTE_TIMEOUT_SECONDS,
            'gc_probability' => '1',
            'gc_divisor' => '100',
        ];

        foreach ($settings as $name => $value) {
            if (ini_get('session.' . $name) !== $value && ini_set('session.' . $name, $value) === false) {
                throw new RuntimeException(sprintf('Could not set session.%s.', $name));
            }
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!is_dir($this->savePath) && !mkdir($this->savePath, 0700, true) && !is_dir($this->savePath)) {
            throw new RuntimeException(sprintf('Could not create session directory "%s".', $this->savePath));
        }

        self::configure($this->savePath);

        // Strict mode replaces an ID that has no session behind it, so a
        // well-formed but unknown cookie never becomes a session.
        $cookie = $request->getCookieParams()[Session::COOKIE_NAME] ?? null;
        $id = is_string($cookie) && preg_match('/^[A-Za-z0-9,-]{22,256}$/', $cookie) === 1 ? $cookie : session_create_id();

        if ($id === false || session_id($id) === false || !session_start()) {
            throw new RuntimeException('Could not start the session.');
        }

        $session = new Session($this->clock);
        $session->enforceTimeouts();

        try {
            $response = $handler->handle($request->withAttribute(Session::class, $session));
        } finally {
            if ($session->isDestroyed()) {
                session_destroy();
            } else {
                $id = session_id();
                session_write_close();
            }
        }

        if ($session->isDestroyed()) {
            return $response->withAddedHeader(
                'Set-Cookie',
                Session::COOKIE_NAME . '=; Path=/; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; HttpOnly; Secure; SameSite=Lax',
            );
        }

        return $response->withAddedHeader(
            'Set-Cookie',
            Session::COOKIE_NAME . '=' . $id . '; Path=/; HttpOnly; Secure; SameSite=Lax',
        );
    }
}
