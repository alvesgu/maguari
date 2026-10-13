<?php

declare(strict_types=1);

namespace Maguari\Server\Http\Controller;

use Maguari\Server\Access\Administrator;
use Maguari\Server\Fleet\FleetApi;
use Maguari\Server\Http\QueryParameters;
use Maguari\Server\Http\View;
use Maguari\Server\Notifications\Exception\NotificationNotFound;
use Maguari\Server\Notifications\NotificationsApi;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

/**
 * Hiding a notification from the dashboard and the Logs screen (design
 * section 10.4). Hiding is per administrator.
 */
final class NotificationsController
{
    public function __construct(
        private readonly NotificationsApi $notifications,
        private readonly FleetApi $fleet,
        private readonly View $view,
    ) {
    }

    /**
     * @param array{id: string} $args
     */
    public function hide(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $this->notifications->hide((int) $args['id'], self::administrator($request)->id);
        } catch (NotificationNotFound) {
            throw new HttpNotFoundException($request);
        }

        return $response->withStatus(303)->withHeader('Location', '/admin');
    }

    /**
     * Every notification, 100 per page, newest first. ?before=<id> shows the
     * ones with a lower ID.
     */
    public function logs(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $before = QueryParameters::all($request->getUri()->getQuery())['before'] ?? [];

        if (count($before) > 1 || ($before !== [] && preg_match('/^[1-9][0-9]{0,17}$/D', $before[0]) !== 1)) {
            throw new HttpBadRequestException($request);
        }

        $names = [];

        foreach ($this->fleet->pickedInstances() as $instance) {
            $names[$instance->id] = $instance->gcpProjectId . '/' . $instance->name;
        }

        return $this->view->render($request, $response, 'logs', [
            'title' => 'Logs',
            'page' => $this->notifications->logPage(self::administrator($request)->id, $before === [] ? null : (int) $before[0]),
            'instanceNames' => $names,
        ]);
    }

    private static function administrator(ServerRequestInterface $request): Administrator
    {
        $administrator = $request->getAttribute(Administrator::class);
        assert($administrator instanceof Administrator);

        return $administrator;
    }
}
