<?php

declare(strict_types=1);

namespace Maguari\Server\Monitoring\Application;

use Maguari\Server\Fleet\FleetApi;
use Maguari\Server\Kernel\Clock;
use Maguari\Server\Kernel\Database\Database;
use Maguari\Server\Monitoring\Domain\CertificateExpiryRule;
use Maguari\Server\Monitoring\Domain\CheckResult;
use Maguari\Server\Monitoring\Domain\DailyJobSummary;
use Maguari\Server\Monitoring\Domain\DailyJobTrigger;
use Maguari\Server\Monitoring\Domain\DiskSizeRule;
use Maguari\Server\Monitoring\Exception\DailyJobAlreadyRunning;
use Maguari\Server\Monitoring\Exception\DailyJobFailed;
use Maguari\Server\Monitoring\Infrastructure\CertificateHostnameRepository;
use Maguari\Server\Monitoring\Infrastructure\DailyJobRepository;
use Maguari\Server\Monitoring\Infrastructure\MetricRunRepository;
use Maguari\Shared\Metric;

/**
 * The daily job (design section 6.3): runs the daily checks on every picked
 * instance and stores their results. The systemd timer and the "Run now"
 * button both run it.
 */
final class DailyJob
{
    /**
     * An unfinished run started longer ago than this was killed (a failure
     * the job could catch marks its run failed instead), and no longer keeps
     * the job from starting.
     */
    public const RUNNING_FOR_AT_MOST_SECONDS = 900;

    /** The timer runs daily, so a scheduled run older than this is overdue. */
    public const OVERDUE_AFTER_SECONDS = 25 * 3600;

    private readonly DailyJobRepository $runs;
    private readonly DiskSizeRule $diskSizeRule;
    private readonly CertificateExpiryRule $certificateRule;

    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
        private readonly FleetApi $fleet,
        private readonly MetricRunRepository $metricRuns,
        private readonly CertificateHostnameRepository $hostnames,
        private readonly ServedCertificates $servedCertificates,
    ) {
        $this->runs = new DailyJobRepository($database);
        $this->diskSizeRule = new DiskSizeRule();
        $this->certificateRule = new CertificateExpiryRule();
    }

    /**
     * @return CheckResult[] by instance, in the order of FleetApi::pickedInstances():
     *         the disk size result, one per local certificate in domain order,
     *         then one per hostname in hostname order
     * @throws DailyJobAlreadyRunning
     * @throws DailyJobFailed after marking the run failed, without results
     */
    public function run(DailyJobTrigger $trigger): array
    {
        $startedAt = $this->clock->now();
        $runId = $this->database->transaction(
            fn (): int => $this->runs->start($trigger, $startedAt, $startedAt - self::RUNNING_FOR_AT_MOST_SECONDS),
        );

        try {
            return $this->checkAndStore($runId);
        } catch (\Throwable $exception) {
            try {
                $this->runs->fail($runId, $this->clock->now());
            } catch (\Throwable) {
                // For example the database itself failing. The run stays
                // unfinished and stops blocking after 15 minutes.
            }

            throw new DailyJobFailed($exception);
        }
    }

    /**
     * @param int[] $instanceIds
     */
    public function summary(array $instanceIds): DailyJobSummary
    {
        $now = $this->clock->now();
        $runningSince = $now - self::RUNNING_FOR_AT_MOST_SECONDS;
        $lastSucceeded = $this->runs->latest($runningSince, succeeded: true);
        $lastScheduled = $this->runs->latest($runningSince, trigger: DailyJobTrigger::Scheduled);

        $results = fn (string $checkName): array => $lastSucceeded === null
            ? []
            : $this->runs->results($lastSucceeded->id, $checkName, $instanceIds);

        // Local results first, then remote, per instance.
        $certificates = $results(CertificateExpiryRule::LOCAL_CHECK_NAME);

        foreach ($results(CertificateExpiryRule::REMOTE_CHECK_NAME) as $instanceId => $remote) {
            $certificates[$instanceId] = [...($certificates[$instanceId] ?? []), ...$remote];
        }

        return new DailyJobSummary(
            $this->runs->latest($runningSince),
            $lastSucceeded,
            $lastScheduled,
            $lastScheduled !== null && $now - $lastScheduled->startedAt > self::OVERDUE_AFTER_SECONDS,
            array_map(static fn (array $list): CheckResult => $list[0], $results(DiskSizeRule::CHECK_NAME)),
            $certificates,
        );
    }

    /**
     * @return CheckResult[]
     */
    private function checkAndStore(int $runId): array
    {
        // Outside any transaction: the API calls take seconds, and holding the
        // write lock that long would block heartbeats.
        $diskSizes = $this->fleet->diskSizes();
        $at = $this->clock->now();
        $results = [];

        foreach ($this->fleet->pickedInstances() as $instance) {
            $disks = $diskSizes[$instance->id] ?? null;
            $results[] = $this->diskSizeRule->check(
                $instance->id,
                $disks?->problem,
                $disks?->bootDisk()?->sizeBytes,
                $this->filesystemTotals($instance->id),
                $at,
            );
            $localCertificates = $this->metricRuns->currentOfKind($instance->id, Metric::CERTIFICATE_EXPIRES_AT);
            array_push($results, ...$this->certificateRule->checkLocal($instance->id, $localCertificates, $at));

            // One or two TLS connections each, still outside any transaction.
            foreach ($this->hostnames->forInstance($instance->id) as $hostname) {
                $results[] = $this->certificateRule->checkRemote(
                    $instance->id,
                    $hostname->hostname,
                    $this->servedCertificates->read($hostname->hostname),
                    $localCertificates[$hostname->hostname] ?? null,
                    $at,
                );
            }
        }

        $this->database->transaction(function () use ($runId, $results): void {
            foreach ($results as $result) {
                $this->runs->addResult($runId, $result);
            }

            $this->runs->finish($runId, $this->clock->now());
        });

        return $results;
    }

    /**
     * @return array<string, ?MetricRun> by mount point
     */
    private function filesystemTotals(int $instanceId): array
    {
        $totals = [];

        foreach (DiskSizeRule::BOOT_DISK_MOUNT_POINTS as $mountPoint) {
            $totals[$mountPoint] = $this->metricRuns->current($instanceId, Metric::name(Metric::DISK_TOTAL_BYTES, $mountPoint));
        }

        return $totals;
    }
}
