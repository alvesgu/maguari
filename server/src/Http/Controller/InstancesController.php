<?php

declare(strict_types=1);

namespace Maguari\Server\Http\Controller;

use Maguari\Server\Clients\ClientsApi;
use Maguari\Server\Fleet\FleetApi;
use Maguari\Server\Fleet\Instance;
use Maguari\Server\Http\Session;
use Maguari\Server\Http\View;
use Maguari\Server\Kernel\Clock;
use Maguari\Server\Monitoring\Domain\HttpCheck;
use Maguari\Server\Monitoring\Exception\InvalidCertificateHostname;
use Maguari\Server\Monitoring\Exception\InvalidClientCheck;
use Maguari\Server\Monitoring\Exception\InvalidHttpCheck;
use Maguari\Server\Monitoring\MonitoringApi;
use Maguari\Server\Remediation\RemediationApi;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * One picked instance's page (design section 8.1 item 4): its incidents, its
 * certificate results, the hostnames its remote certificate check connects
 * to, its remote HTTP checks and its client's checks. Reads only SQLite;
 * adding a hostname or a web app connects to nothing, and the client gets a
 * new service check with its next heartbeat.
 */
final class InstancesController
{
    public function __construct(
        private readonly FleetApi $fleet,
        private readonly MonitoringApi $monitoring,
        private readonly RemediationApi $remediation,
        private readonly ClientsApi $clients,
        private readonly View $view,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array{id: string} $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->page($request, $response, $this->instance($request, $args), ['notice' => self::session($request)->takeFlash()]);
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
            return $this->page($request, $response, $instance, ['hostname' => $hostname, 'error' => $invalid->getMessage()], 422);
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
    public function addHttpCheck(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $instance = $this->instance($request, $args);
        $input = (array) $request->getParsedBody();
        $url = FormInput::string($input, 'url');
        $interval = FormInput::string($input, 'interval');

        try {
            $saved = $this->monitoring->addHttpCheck($instance->id, $url, $interval);
        } catch (InvalidHttpCheck $invalid) {
            return $this->page($request, $response, $instance, ['httpUrl' => $url, 'httpInterval' => $interval, 'httpError' => $invalid->getMessage()], 422);
        }

        if ($saved->warning !== null) {
            self::session($request)->flash($saved->warning);
        }

        return self::backTo($response, $instance);
    }

    /**
     * A check that is already gone just shows the page again.
     *
     * @param array{id: string, checkId: string} $args
     */
    public function changeHttpCheckInterval(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $instance = $this->instance($request, $args);
        $interval = FormInput::string((array) $request->getParsedBody(), 'interval');

        try {
            $saved = $this->monitoring->changeHttpCheckInterval($instance->id, (int) $args['checkId'], $interval);
        } catch (InvalidHttpCheck $invalid) {
            return $this->page($request, $response, $instance, ['intervalError' => $invalid->getMessage()], 422);
        }

        if ($saved?->warning !== null) {
            self::session($request)->flash($saved->warning);
        }

        return self::backTo($response, $instance);
    }

    /**
     * @param array{id: string, checkId: string} $args
     */
    public function removeHttpCheck(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $instance = $this->instance($request, $args);
        $this->monitoring->removeHttpCheck($instance->id, (int) $args['checkId']);

        return self::backTo($response, $instance);
    }

    /**
     * @param array{id: string} $args
     */
    public function addService(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $instance = $this->instance($request, $args);
        $unit = FormInput::string((array) $request->getParsedBody(), 'unit');

        try {
            $this->monitoring->addServiceCheck($instance->id, $unit);
        } catch (InvalidClientCheck $invalid) {
            return $this->page($request, $response, $instance, ['unit' => $unit, 'unitError' => $invalid->getMessage()], 422);
        }

        return self::backTo($response, $instance);
    }

    /**
     * @param array{id: string} $args
     */
    public function addLocalHttp(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $instance = $this->instance($request, $args);
        $input = (array) $request->getParsedBody();
        $url = FormInput::string($input, 'local_url');
        $hostName = FormInput::string($input, 'host_name');

        try {
            $this->monitoring->addLocalHttpCheck($instance->id, $url, $hostName);
        } catch (InvalidClientCheck $invalid) {
            return $this->page($request, $response, $instance, ['localUrl' => $url, 'hostName' => $hostName, 'localHttpError' => $invalid->getMessage()], 422);
        }

        return self::backTo($response, $instance);
    }

    /**
     * @param array{id: string} $args
     */
    public function addDatabase(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $instance = $this->instance($request, $args);
        $name = FormInput::string((array) $request->getParsedBody(), 'database');

        try {
            $this->monitoring->addDatabaseCheck($instance->id, $name);
        } catch (InvalidClientCheck $invalid) {
            return $this->page($request, $response, $instance, ['database' => $name, 'databaseError' => $invalid->getMessage()], 422);
        }

        return self::backTo($response, $instance);
    }

    /**
     * A check that is already gone just shows the page again.
     *
     * @param array{id: string, checkId: string} $args
     */
    public function removeClientCheck(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $instance = $this->instance($request, $args);
        $this->monitoring->removeClientCheck($instance->id, (int) $args['checkId']);

        return self::backTo($response, $instance);
    }

    /**
     * @param array{id: string} $args
     */
    private function instance(ServerRequestInterface $request, array $args): Instance
    {
        return $this->fleet->pickedInstance((int) $args['id']) ?? throw new HttpNotFoundException($request);
    }

    private static function session(ServerRequestInterface $request): Session
    {
        $session = $request->getAttribute(Session::class);
        assert($session instanceof Session);

        return $session;
    }

    private static function backTo(ResponseInterface $response, Instance $instance): ResponseInterface
    {
        return $response->withStatus(303)->withHeader('Location', '/admin/instances/' . $instance->id);
    }

    /**
     * @param array{hostname?: string, error?: string, httpUrl?: string, httpInterval?: string, httpError?: string, intervalError?: string, notice?: ?string, unit?: string, unitError?: string, localUrl?: string, hostName?: string, localHttpError?: string, database?: string, databaseError?: string} $form
     *        what was typed in a refused form and why it was refused, or the
     *        warning of the last change
     */
    private function page(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Instance $instance,
        array $form = [],
        int $status = 200,
    ): ResponseInterface {
        $summary = $this->monitoring->dailyJobSummary([$instance->id]);

        return $this->view->render($request, $response, 'instance', [
            'title' => $instance->name,
            'instance' => $instance,
            'incidents' => $this->remediation->incidents($instance->id),
            'certificates' => $summary->certificateResults[$instance->id] ?? [],
            'lastRun' => $summary->lastRun,
            'resultsRun' => $summary->lastSucceededRun,
            'hostnames' => $this->monitoring->certificateHostnames($instance->id),
            'suggestions' => $this->monitoring->certificateHostnameSuggestions($instance->id),
            'hostname' => $form['hostname'] ?? '',
            'error' => $form['error'] ?? null,
            'httpChecks' => $this->monitoring->httpCheckStatuses([$instance->id])[$instance->id] ?? [],
            'httpEgressBytes' => $this->monitoring->httpCheckMonthlyEgressBytes(),
            'httpUrl' => $form['httpUrl'] ?? '',
            'httpInterval' => $form['httpInterval'] ?? (string) HttpCheck::DEFAULT_INTERVAL_MINUTES,
            'httpError' => $form['httpError'] ?? null,
            'intervalError' => $form['intervalError'] ?? null,
            'notice' => $form['notice'] ?? null,
            'clientChecks' => $this->monitoring->clientCheckStatuses([$instance->id])[$instance->id] ?? [],
            'clientCheckSettings' => $this->monitoring->clientCheckSettings($instance->id),
            'reportedCheckSettings' => $this->clients->reportedCheckSettings([$instance->id])[$instance->id] ?? null,
            'clientVersion' => $this->clients->clientVersions([$instance->id])[$instance->id] ?? null,
            'unit' => $form['unit'] ?? '',
            'unitError' => $form['unitError'] ?? null,
            'localUrl' => $form['localUrl'] ?? '',
            'hostName' => $form['hostName'] ?? '',
            'localHttpError' => $form['localHttpError'] ?? null,
            'database' => $form['database'] ?? '',
            'databaseError' => $form['databaseError'] ?? null,
            'now' => $this->clock->now(),
        ], $status);
    }
}
