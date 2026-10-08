<?php

declare(strict_types=1);

namespace Maguari\Server\Http\Controller;

use Maguari\Server\Fleet\FleetApi;
use Maguari\Server\Fleet\Instance;
use Maguari\Server\Http\View;
use Maguari\Server\Monitoring\Exception\InvalidCertificateHostname;
use Maguari\Server\Monitoring\MonitoringApi;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * One picked instance's page (design section 8.1 item 4): its certificate
 * results and the hostnames its remote certificate check connects to. Reads
 * only SQLite; adding a hostname connects to nothing.
 */
final class InstancesController
{
    public function __construct(
        private readonly FleetApi $fleet,
        private readonly MonitoringApi $monitoring,
        private readonly View $view,
    ) {
    }

    /**
     * @param array{id: string} $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->page($request, $response, $this->instance($request, $args), '', null);
    }

    /**
     * @param array{id: string} $args
     */
    public function addHostname(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $instance = $this->instance($request, $args);
        $hostname = FormInput::string((array) $request->getParsedBody(), 'hostname');

        try {
            $this->monitoring->addCertificateHostname($instance->id, $hostname);
        } catch (InvalidCertificateHostname $invalid) {
            return $this->page($request, $response, $instance, $hostname, $invalid->getMessage(), 422);
        }

        return self::backTo($response, $instance);
    }

    /**
     * Removing one that is already gone (a second click, another tab) just
     * shows the page again.
     *
     * @param array{id: string, hostnameId: string} $args
     */
    public function removeHostname(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $instance = $this->instance($request, $args);
        $this->monitoring->removeCertificateHostname($instance->id, (int) $args['hostnameId']);

        return self::backTo($response, $instance);
    }

    /**
     * @param array{id: string} $args
     */
    private function instance(ServerRequestInterface $request, array $args): Instance
    {
        return $this->fleet->pickedInstance((int) $args['id']) ?? throw new HttpNotFoundException($request);
    }

    private static function backTo(ResponseInterface $response, Instance $instance): ResponseInterface
    {
        return $response->withStatus(303)->withHeader('Location', '/admin/instances/' . $instance->id);
    }

    private function page(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Instance $instance,
        string $hostname,
        ?string $error,
        int $status = 200,
    ): ResponseInterface {
        $summary = $this->monitoring->dailyJobSummary([$instance->id]);

        return $this->view->render($request, $response, 'instance', [
            'title' => $instance->name,
            'instance' => $instance,
            'certificates' => $summary->certificateResults[$instance->id] ?? [],
            'resultsRun' => $summary->lastSucceededRun,
            'hostnames' => $this->monitoring->certificateHostnames($instance->id),
            'suggestions' => $this->monitoring->certificateHostnameSuggestions($instance->id),
            'hostname' => $hostname,
            'error' => $error,
        ], $status);
    }
}
