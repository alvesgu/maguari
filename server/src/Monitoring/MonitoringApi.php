<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring;

use Maguari\Server\Fleet\FleetApi;
use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Kernel\Events\EventOutbox;
use Maguari\Server\Kernel\HttpProbe\HttpProbe;
use Maguari\Server\Kernel\HttpProbe\StreamHttpProbe;
use Maguari\Server\Kernel\Tls\TlsCertificateReader;
use Maguari\Server\Monitoring\Application\CertificateHostnames;
use Maguari\Server\Monitoring\Application\ClientChecks;
use Maguari\Server\Monitoring\Application\CheckStates;
use Maguari\Server\Monitoring\Application\DailyJob;
use Maguari\Server\Monitoring\Application\HeartbeatAges;
use Maguari\Server\Monitoring\Application\HttpChecks;
use Maguari\Server\Monitoring\Application\ServedCertificates;
use Maguari\Server\Monitoring\Domain\CertificateHostname;
use Maguari\Server\Monitoring\Domain\CheckLabels;
use Maguari\Server\Monitoring\Domain\CheckOutcome;
use Maguari\Server\Monitoring\Domain\CheckResult;
use Maguari\Server\Monitoring\Domain\CheckState;
use Maguari\Server\Monitoring\Domain\ClientCheck;
use Maguari\Server\Monitoring\Domain\ClientCheckResults;
use Maguari\Server\Monitoring\Domain\ClientCheckSettings;
use Maguari\Server\Monitoring\Domain\ClientCheckStatus;
use Maguari\Server\Monitoring\Domain\DailyJobSummary;
use Maguari\Server\Monitoring\Domain\DailyJobTrigger;
use Maguari\Server\Monitoring\Domain\HeartbeatStatus;
use Maguari\Server\Monitoring\Domain\HttpCheck;
use Maguari\Server\Monitoring\Domain\HttpCheckSaved;
use Maguari\Server\Monitoring\Domain\HttpCheckStatus;
use Maguari\Server\Monitoring\Domain\Readings;
use Maguari\Server\Monitoring\Domain\RunDecision;
use Maguari\Server\Monitoring\Domain\RunQuery;
use Maguari\Server\Monitoring\Domain\RunRule;
use Maguari\Server\Monitoring\Domain\RunSeries;
use Maguari\Server\Monitoring\Exception\DailyJobAlreadyRunning;
use Maguari\Server\Monitoring\Exception\DailyJobFailed;
use Maguari\Server\Monitoring\Exception\InvalidCertificateHostname;
use Maguari\Server\Monitoring\Exception\InvalidCheckResults;
use Maguari\Server\Monitoring\Exception\InvalidClientCheck;
use Maguari\Server\Monitoring\Exception\InvalidHttpCheck;
use Maguari\Server\Monitoring\Exception\InvalidReadings;
use Maguari\Server\Monitoring\Exception\InvalidRunQuery;
use Maguari\Server\Monitoring\Infrastructure\CertificateHostnameRepository;
use Maguari\Server\Monitoring\Infrastructure\CheckStateRepository;
use Maguari\Server\Monitoring\Infrastructure\ClientCheckRepository;
use Maguari\Server\Monitoring\Infrastructure\FailureStreakRepository;
use Maguari\Server\Monitoring\Infrastructure\HeartbeatRepository;
use Maguari\Server\Monitoring\Infrastructure\HttpCheckRepository;
use Maguari\Server\Monitoring\Infrastructure\MetricRunRepository;
use Maguari\Shared\Protocol;

/**
 * The Monitoring context's public interface: readings stored as runs, the
 * heartbeat-age check, and the daily job with its checks. Instances are
 * referred to by their Fleet ID. Monitoring does not know where heartbeats
 * come from. It asks Fleet for the picked instances and their disks, and
 * writes an event when a check's outcome changes (design section 6.3).
 */
final class MonitoringApi
{
    /**
     * How often readings are expected. Fixed at the default heartbeat
     * interval until the interval becomes configurable (design section 7.3).
     */
    public const EXPECTED_INTERVAL_SECONDS = Protocol::HEARTBEAT_INTERVAL_SECONDS;

    /**
     * The most runs one read returns (design section 9.1): about 150 KB of
     * JSON. A longer series keeps its newest runs.
     */
    public const MAX_RUNS = 5_000;

    private readonly MetricRunRepository $runs;
    private readonly RunRule $runRule;
    private readonly DailyJob $dailyJob;
    private readonly CertificateHostnames $certificateHostnames;
    private readonly HeartbeatRepository $heartbeats;
    private readonly HeartbeatAges $heartbeatAges;
    private readonly CheckStateRepository $checkStates;
    private readonly HttpChecks $httpChecks;
    private readonly ClientChecks $clientChecks;

    /**
     * @param TlsCertificateReader $tls reads the certificates hostnames serve
     * @param HttpProbe $httpProbe requests the web apps of remote HTTP checks
     * @param ?\Closure(int): void $sleep waits before a remote HTTP check's
     *        retry; null for sleep()
     */
    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
        FleetApi $fleet,
        TlsCertificateReader $tls,
        HttpProbe $httpProbe = new StreamHttpProbe(),
        ?\Closure $sleep = null,
    ) {
        $this->runs = new MetricRunRepository($database);
        $this->runRule = new RunRule(self::EXPECTED_INTERVAL_SECONDS);
        $hostnames = new CertificateHostnameRepository($database);
        $this->checkStates = new CheckStateRepository($database);
        $streaks = new FailureStreakRepository($database);
        $checkStates = new CheckStates($this->checkStates, new EventOutbox($database), $streaks);
        $this->clientChecks = new ClientChecks($database, $clock, new ClientCheckRepository($database), $this->checkStates, $streaks, $checkStates);
        $this->dailyJob = new DailyJob($database, $clock, $fleet, $this->runs, $hostnames, new ServedCertificates($tls), $checkStates);
        $this->certificateHostnames = new CertificateHostnames($database, $clock, $hostnames, $this->runs, $checkStates);
        $this->heartbeats = new HeartbeatRepository($database);
        $this->heartbeatAges = new HeartbeatAges($database, $clock, $fleet, $this->heartbeats, $checkStates);
        $this->httpChecks = new HttpChecks(
            $database,
            $clock,
            $fleet,
            new HttpCheckRepository($database),
            $this->checkStates,
            $checkStates,
            $httpProbe,
            $sleep ?? static function (int $seconds): void {
                sleep($seconds);
            },
        );
    }

    /**
     * Runs the daily checks on every picked instance and stores the results
     * (design section 6.3). Calls the Compute Engine API, so it takes seconds.
     *
     * @return CheckResult[] by instance, in the order of FleetApi::pickedInstances():
     *         the disk size result, then one per certificate in domain order
     * @throws DailyJobAlreadyRunning
     * @throws DailyJobFailed after marking the run failed. The caller logs it.
     */
    public function runDailyJob(DailyJobTrigger $trigger): array
    {
        return $this->dailyJob->run($trigger);
    }

    /**
     * The daily job's latest runs and the given instances' latest disk size
     * and certificate results, for the dashboard. Reads only SQLite.
     *
     * @param int[] $instanceIds
     */
    public function dailyJobSummary(array $instanceIds): DailyJobSummary
    {
        return $this->dailyJob->summary($instanceIds);
    }

    /**
     * Judges every picked instance's heartbeat age (design section 6.2) and
     * writes the outcome changes as events, without delivering them. Run by
     * the tick, never inside a request.
     *
     * @return list<CheckResult> one per picked instance that has sent a heartbeat
     */
    public function judgeHeartbeatAges(): array
    {
        return $this->heartbeatAges->judge();
    }

    /**
     * Each instance's last heartbeat and whether it is on time, computed now,
     * for pages. Reads only SQLite.
     *
     * @param int[] $instanceIds
     * @return array<int, HeartbeatStatus> one per ID
     */
    public function heartbeatStatuses(array $instanceIds): array
    {
        return $this->heartbeatAges->statuses($instanceIds);
    }

    /**
     * Every check currently failing, with the time it started failing and
     * its latest sentence where one was stored, for Remediation's daily
     * reconciliation (design section 7.4). Reads only
     * SQLite, so it can run inside the caller's transaction.
     *
     * @return list<CheckState> in instance, check name and subject order
     */
    public function failingChecks(): array
    {
        return $this->checkStates->failing();
    }

    /**
     * The latest sentence Monitoring stored for the check while it is
     * failing, or null when it is not failing or none was stored. For
     * Notifications' hourly reconciliation (design section 10.4 item 8).
     * Reads only SQLite.
     */
    public function failingSentence(int $instanceId, string $checkName, string $subject): ?string
    {
        $state = $this->checkStates->find($instanceId, $checkName, $subject);

        return $state?->outcome === CheckOutcome::Fail ? $state->detail : null;
    }

    /**
     * When the check started passing, or null when it is failing or has no
     * outcome (never checked, only "not checked", or withdrawn).
     */
    public function passingSince(int $instanceId, string $checkName, string $subject): ?int
    {
        $state = $this->checkStates->find($instanceId, $checkName, $subject);

        return $state?->outcome === CheckOutcome::Pass ? $state->since : null;
    }

    /**
     * A check's name for people, for example "Disk size".
     */
    public static function checkLabel(string $checkName): string
    {
        return CheckLabels::of($checkName);
    }

    /**
     * The hostnames whose served certificate the daily job checks for this
     * instance (design section 6.2).
     *
     * @return list<CertificateHostname> in hostname order
     */
    public function certificateHostnames(int $instanceId): array
    {
        return $this->certificateHostnames->forInstance($instanceId);
    }

    /**
     * The next daily job checks it; adding it connects to nothing.
     *
     * @throws InvalidCertificateHostname with a sentence for the administrator
     */
    public function addCertificateHostname(int $instanceId, string $hostname): CertificateHostname
    {
        return $this->certificateHostnames->add($instanceId, $hostname);
    }

    /**
     * @return bool whether it existed for that instance
     */
    public function removeCertificateHostname(int $instanceId, int $hostnameId): bool
    {
        return $this->certificateHostnames->remove($instanceId, $hostnameId);
    }

    /**
     * The checks the instance's client runs (design section 6.1.3).
     *
     * @return list<ClientCheck> in type and target order
     */
    public function clientChecks(int $instanceId): array
    {
        return $this->clientChecks->forInstance($instanceId);
    }

    /**
     * What the instance's client should run, with its version, for heartbeat
     * responses (design section 5.2). Reads only SQLite.
     */
    public function clientCheckSettings(int $instanceId): ClientCheckSettings
    {
        return $this->clientChecks->settings($instanceId);
    }

    /**
     * Each instance's client checks with their current outcomes, for pages.
     * Reads only SQLite.
     *
     * @param int[] $instanceIds
     * @return array<int, list<ClientCheckStatus>> by instance ID, only instances with checks
     */
    public function clientCheckStatuses(array $instanceIds): array
    {
        return $this->clientChecks->statuses($instanceIds);
    }

    /**
     * The client gets it with its next heartbeat's response.
     *
     * @throws InvalidClientCheck with a sentence for the administrator
     */
    public function addServiceCheck(int $instanceId, string $unit): ClientCheck
    {
        return $this->clientChecks->addService($instanceId, $unit);
    }

    /**
     * A URL on the instance itself, with an optional host name for the Host
     * header and TLS. The client gets it with its next heartbeat's response.
     *
     * @throws InvalidClientCheck with a sentence for the administrator
     */
    public function addLocalHttpCheck(int $instanceId, string $url, string $hostName): ClientCheck
    {
        return $this->clientChecks->addLocalHttp($instanceId, $url, $hostName);
    }

    /**
     * The name of a database set up on the instance (maguari-client
     * set-database); how to log in stays on the instance. The client gets it
     * with its next heartbeat's response.
     *
     * @throws InvalidClientCheck with a sentence for the administrator
     */
    public function addDatabaseCheck(int $instanceId, string $name): ClientCheck
    {
        return $this->clientChecks->addDatabase($instanceId, $name);
    }

    /**
     * Withdraws its outcome at once, so its incident resolves.
     *
     * @return bool whether it existed for that instance
     */
    public function removeClientCheck(int $instanceId, int $checkId): bool
    {
        return $this->clientChecks->remove($instanceId, $checkId);
    }

    /**
     * Validates a heartbeat's check results without writing anything, so the
     * caller can reject the whole heartbeat first.
     *
     * @param list<mixed> $checks as decoded from JSON
     * @throws InvalidCheckResults
     */
    public function parseCheckResults(array $checks): ClientCheckResults
    {
        return ClientCheckResults::parse($checks);
    }

    /**
     * Requests every remote HTTP check that is due (design section 6.2.1)
     * and stores the results, writing outcome changes as events without
     * delivering them. Run by maguari-server run-http-checks every minute,
     * never inside a request. Takes under a minute.
     *
     * @return list<CheckResult> in the order checked
     */
    public function runDueHttpChecks(): array
    {
        return $this->httpChecks->runDue();
    }

    /**
     * @return list<HttpCheck> in URL order
     */
    public function httpChecks(int $instanceId): array
    {
        return $this->httpChecks->forInstance($instanceId);
    }

    /**
     * Each instance's remote HTTP checks with their current outcomes, for
     * pages. Reads only SQLite.
     *
     * @param int[] $instanceIds
     * @return array<int, list<HttpCheckStatus>> by instance ID, only instances with checks
     */
    public function httpCheckStatuses(array $instanceIds): array
    {
        return $this->httpChecks->statuses($instanceIds);
    }

    /**
     * The estimated monthly egress of all remote HTTP checks together
     * (HttpCheckEgress).
     */
    public function httpCheckMonthlyEgressBytes(): int
    {
        return $this->httpChecks->monthlyEgressBytes();
    }

    /**
     * Due at once; adding it connects to nothing. Above the egress budget it
     * saves with a warning; above the limit it is refused.
     *
     * @param string $intervalMinutes as typed; empty for the default
     * @throws InvalidHttpCheck with a sentence for the administrator
     */
    public function addHttpCheck(int $instanceId, string $url, string $intervalMinutes): HttpCheckSaved
    {
        return $this->httpChecks->add($instanceId, $url, $intervalMinutes);
    }

    /**
     * Like adding, a shorter interval above the egress budget saves with a
     * warning and above the limit is refused.
     *
     * @return ?HttpCheckSaved null when it does not exist for that instance
     * @throws InvalidHttpCheck with a sentence for the administrator
     */
    public function changeHttpCheckInterval(int $instanceId, int $checkId, string $intervalMinutes): ?HttpCheckSaved
    {
        return $this->httpChecks->changeInterval($instanceId, $checkId, $intervalMinutes);
    }

    /**
     * Withdraws its outcome at once, so its incident resolves.
     *
     * @return bool whether it existed for that instance
     */
    public function removeHttpCheck(int $instanceId, int $checkId): bool
    {
        return $this->httpChecks->remove($instanceId, $checkId);
    }

    /**
     * Domains of the instance's recently reported certificates that could be
     * added as hostnames and are not yet. Reads only SQLite.
     *
     * @return list<string>
     */
    public function certificateHostnameSuggestions(int $instanceId): array
    {
        return $this->certificateHostnames->suggestions($instanceId);
    }

    /**
     * The runs of one metric that overlap a time range, for charts (design
     * section 9.1). Reads only SQLite. The range defaults to the last 24 hours.
     *
     * @param array<string, list<string>> $parameters metric, and optionally
     *        from and to in Unix seconds, with every value given for each
     * @throws InvalidRunQuery with a sentence for the administrator
     */
    public function runs(int $instanceId, array $parameters): RunSeries
    {
        $query = RunQuery::parse($parameters, $this->clock->now());
        // One more than the limit, only to tell whether there are more.
        $runs = $this->runs->overlapping($instanceId, $query->metric, $query->from, $query->to, self::MAX_RUNS + 1);
        $truncated = count($runs) > self::MAX_RUNS;

        return new RunSeries(
            $query,
            $truncated ? array_slice($runs, 1) : $runs,
            $this->runRule->maxGapSeconds(),
            $truncated,
        );
    }

    /**
     * Validates a heartbeat's readings without writing anything, so the
     * caller can reject the whole heartbeat first.
     *
     * @param list<mixed> $readings as decoded from JSON
     * @throws InvalidReadings
     */
    public function parseReadings(array $readings): Readings
    {
        return Readings::parse($readings);
    }

    /**
     * Records a heartbeat's time, which the heartbeat-age check judges,
     * applies the run rule (design section 9.1) to each reading and judges
     * the client's check results, writing outcome changes as events, all in
     * one transaction, so concurrent heartbeats of one instance cannot both
     * extend or both insert. The time is recorded even without readings.
     *
     * @param int $at the server's receive time
     */
    public function recordHeartbeat(int $instanceId, int $at, Readings $readings, ?ClientCheckResults $results = null): void
    {
        $this->database->transaction(function () use ($instanceId, $at, $readings, $results): void {
            $this->heartbeats->record($instanceId, $at);

            foreach ($readings->readings as $reading) {
                $current = $this->runs->current($instanceId, $reading->metric);

                match ($this->runRule->decide($current, $reading, $at)) {
                    RunDecision::Insert => $this->runs->insert($instanceId, $reading->metric, $reading->value, $at),
                    RunDecision::Extend => $this->runs->extend($current->id, $at),
                    RunDecision::Ignore => null,
                };
            }

            $this->clientChecks->record($instanceId, $at, $results ?? ClientCheckResults::none());
        });
    }
}
