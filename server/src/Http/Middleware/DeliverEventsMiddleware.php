<?php

declare(strict_types=1);

namespace Maguari\Server\Http\Middleware;

use Maguari\Server\Kernel\Events\EventDelivery;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * After an administrator's state-changing request, delivers the pending
 * events before responding (design section 2.1 rule 5), so an incident its
 * action resolved (removing a check, for example) is gone from the next page.
 * Delivery only writes to the database; alert emails still go out on the
 * next tick. GET requests change nothing and deliver nothing. A failure is
 * logged and never fails the request: its action is already committed, and
 * the tick tries the event again.
 */
final class DeliverEventsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly EventDelivery $delivery,
        private readonly bool $logErrors = true,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $response;
        }

        try {
            $report = $this->delivery->deliver();
            $failures = array_map(
                static fn ($failure): string => sprintf('Maguari: the event %d (%s) failed after %s: %s: %s', $failure->id, $failure->type, $request->getUri()->getPath(), $failure->exception::class, $failure->exception->getMessage()),
                $report->failures,
            );
        } catch (\Throwable $exception) {
            $failures = [sprintf('Maguari: delivering events after %s failed: %s: %s', $request->getUri()->getPath(), $exception::class, $exception->getMessage())];
        }

        if ($this->logErrors) {
            array_map('error_log', $failures);
        }

        return $response;
    }
}
