<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring;

use Maguari\Server\Fleet\FleetApi;
use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Kernel\Tls\TlsCertificateReader;
use Maguari\Server\Monitoring\Application\CertificateHostnames;
use Maguari\Server\Monitoring\Application\DailyJob;
use Maguari\Server\Monitoring\Application\ServedCertificates;
use Maguari\Server\Monitoring\Domain\CertificateHostname;
use Maguari\Server\Monitoring\Domain\CheckResult;
use Maguari\Server\Monitoring\Domain\DailyJobSummary;
use Maguari\Server\Monitoring\Domain\DailyJobTrigger;
use Maguari\Server\Monitoring\Domain\Readings;
use Maguari\Server\Monitoring\Domain\RunDecision;
use Maguari\Server\Monitoring\Domain\RunQuery;
use Maguari\Server\Monitoring\Domain\RunRule;
use Maguari\Server\Monitoring\Domain\RunSeries;
use Maguari\Server\Monitoring\Exception\DailyJobAlreadyRunning;
use Maguari\Server\Monitoring\Exception\DailyJobFailed;
use Maguari\Server\Monitoring\Exception\InvalidCertificateHostname;
use Maguari\Server\Monitoring\Exception\InvalidReadings;
use Maguari\Server\Monitoring\Exception\InvalidRunQuery;
use Maguari\Server\Monitoring\Infrastructure\CertificateHostnameRepository;
use Maguari\Server\Monitoring\Infrastructure\MetricRunRepository;
use Maguari\Shared\Protocol;

/**
 * The Monitoring context's public interface: readings stored as runs, and the
 * daily job with its checks. Instances are referred to by their Fleet ID.
 * Monitoring does not know where readings come from. It asks Fleet for the
 * picked instances and their disks.
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

    /**
     * @param TlsCertificateReader $tls reads the certificates hostnames serve
     */
    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
        FleetApi $fleet,
        TlsCertificateReader $tls,
    ) {
        $this->runs = new MetricRunRepository($database);
        $this->runRule = new RunRule(self::EXPECTED_INTERVAL_SECONDS);
        $hostnames = new CertificateHostnameRepository($database);
        $this->dailyJob = new DailyJob($database, $clock, $fleet, $this->runs, $hostnames, new ServedCertificates($tls));
        $this->certificateHostnames = new CertificateHostnames($database, $clock, $hostnames, $this->runs);
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
     * Applies the run rule (design section 9.1) to each reading, all in one
     * transaction, so concurrent heartbeats of one instance cannot both
     * extend or both insert.
     *
     * @param int $at the server's receive time
     */
    public function recordReadings(int $instanceId, int $at, Readings $readings): void
    {
        if ($readings->readings === []) {
            return;
        }

        $this->database->transaction(function () use ($instanceId, $at, $readings): void {
            foreach ($readings->readings as $reading) {
                $current = $this->runs->current($instanceId, $reading->metric);

                match ($this->runRule->decide($current, $reading, $at)) {
                    RunDecision::Insert => $this->runs->insert($instanceId, $reading->metric, $reading->value, $at),
                    RunDecision::Extend => $this->runs->extend($current->id, $at),
                    RunDecision::Ignore => null,
                };
            }
        });
    }
}
