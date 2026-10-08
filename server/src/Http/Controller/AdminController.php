<?php

declare(strict_types=1);

namespace Maguari\Server\Http\Controller;

use Maguari\Server\Access\Administrator;
use Maguari\Server\Clients\ClientsApi;
use Maguari\Server\Fleet\FleetApi;
use Maguari\Server\Fleet\Instance;
use Maguari\Server\Http\Session;
use Maguari\Server\Http\View;
use Maguari\Server\Monitoring\Domain\DailyJobTrigger;
use Maguari\Server\Monitoring\Exception\DailyJobAlreadyRunning;
use Maguari\Server\Monitoring\Exception\DailyJobFailed;
use Maguari\Server\Monitoring\MonitoringApi;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The dashboard: every picked instance with its heartbeat status (design
 * section 15 step 6) and the daily job with its latest results (step 8). It
 * reads only SQLite, so it works while Google Cloud's API does not.
 */
final class AdminController
{
    /**
     * @param bool $logErrors log a failed daily job to PHP's error log. Tests pass false.
     */
    public function __construct(
        private readonly FleetApi $fleet,
        private readonly ClientsApi $clients,
        private readonly MonitoringApi $monitoring,
        private readonly View $view,
        private readonly bool $logErrors = true,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $instances = $this->fleet->pickedInstances();
        $ids = array_map(static fn (Instance $instance): int => $instance->id, $instances);

        return $this->view->render($request, $response, 'admin', [
            'title' => 'Dashboard',
            'administrator' => $request->getAttribute(Administrator::class),
            'instances' => $instances,
            'enrollmentStates' => $this->clients->enrollmentStates($ids),
            'heartbeats' => $this->clients->heartbeatStatuses($ids),
            'dailyJob' => $this->monitoring->dailyJobSummary($ids),
        ]);
    }

    /**
     * "Run now" (design section 6.3): runs the daily job inside the request,
     * then shows the page the button was on, which says how the run went: the
     * instance page when the form names a picked instance, otherwise the
     * dashboard. The address is built from the instance's ID, never taken
     * from the request.
     */
    public function runDailyJob(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $this->monitoring->runDailyJob(DailyJobTrigger::Manual);
        } catch (DailyJobAlreadyRunning) {
            // The dashboard shows the run in progress.
        } catch (DailyJobFailed $failed) {
            // The dashboard shows the run failed; the details go to the log only.
            if ($this->logErrors) {
                error_log('Maguari: ' . $failed->getMessage());
            }
        }

        $instanceId = FormInput::string((array) $request->getParsedBody(), 'instance_id');
        $instance = preg_match('/^[1-9][0-9]{0,17}$/D', $instanceId) === 1 ? $this->fleet->pickedInstance((int) $instanceId) : null;

        return $response->withStatus(303)->withHeader('Location', $instance === null ? '/admin' : '/admin/instances/' . $instance->id);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $request->getAttribute(Session::class);
        assert($session instanceof Session);
        $session->destroy();

        return $response->withStatus(303)->withHeader('Location', '/auth/login');
    }
}
