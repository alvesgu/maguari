<?php

declare(strict_types=1);

namespace Maguari\Server\Http;

use Slim\Exception\HttpException;
use Slim\Interfaces\ErrorRendererInterface;
use Throwable;

/**
 * Maguari's HTML error pages: a fixed title and sentence, and a plain link to
 * the dashboard. No inline JavaScript or CSS, so the Content-Security-Policy
 * needs no exceptions. Exception messages are never shown.
 */
final class ErrorPageRenderer implements ErrorRendererInterface
{
    public function __construct(
        private readonly View $view,
    ) {
    }

    public function __invoke(Throwable $exception, bool $displayErrorDetails): string
    {
        // Slim's titles and descriptions are fixed per status code; a
        // message passed to the exception is not.
        if ($exception instanceof HttpException) {
            $title = $exception->getTitle();
            $message = $exception->getDescription();
        } else {
            $title = '500 Internal Server Error';
            $message = 'Something went wrong on the server. Try again later.';
        }

        return $this->view->page('error', ['title' => $title, 'message' => $message]);
    }
}
