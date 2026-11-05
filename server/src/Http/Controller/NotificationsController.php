<?php

declare(strict_types=1);

namespace Maguari\Server\Http\Controller;

use Maguari\Server\Access\Administrator;
use Maguari\Server\Fleet\FleetApi;
use Maguari\Server\Http\QueryParameters;
use Maguari\Server\Http\View;
use Maguari\Server\Notifications\Exception\NotificationNotFound;
use Maguari\Server\Notifications\NotificationsApi;
use Maguari\Server\Remediation\RemediationApi;
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
        private readonly RemediationApi $remediation,
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
     * ones with a lower ID. Every action too, with ?actions_before=<id>.
     */
    public function logs(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $parameters = QueryParameters::all($request->getUri()->getQuery());
        $before = self::idParameter($request, $parameters['before'] ?? []);
        $actionsBefore = self::idParameter($request, $parameters['actions_before'] ?? []);

        $names = [];

        foreach ($this->fleet->pickedInstances() as $instance) {
            $names[$instance->id] = $instance->gcpProjectId . '/' . $instance->name;
        }

        return $this->view->render($request, $response, 'logs', [
            'title' => 'Logs',
            'page' => $this->notifications->logPage(self::administrator($request)->id, $before),
            'instanceNames' => $names,
            'actions' => $this->remediation->recentActions($actionsBefore),
            'actionsBefore' => $actionsBefore,
        ]);
    }

    /**
     * @param list<string> $values
     * @throws HttpBadRequestException when given twice or not a positive integer
     */
    private static function idParameter(ServerRequestInterface $request, array $values): ?int
    {
        if (count($values) > 1 || ($values !== [] && preg_match('/^[1-9][0-9]{0,17}$/D', $values[0]) !== 1)) {
            throw new HttpBadRequestException($request);
        }

        return $values === [] ? null : (int) $values[0];
    }

    private static function administrator(ServerRequestInterface $request): Administrator
    {
        $administrator = $request->getAttribute(Administrator::class);
        assert($administrator instanceof Administrator);

        return $administrator;
    }
}
