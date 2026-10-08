<?php declare(strict_types=1);
/**
 * @var \Closure $e @var string $csrf @var \Maguari\Server\Access\Administrator $administrator
 * @var \Maguari\Server\Fleet\Instance[] $instances
 * @var array<int, \Maguari\Server\Clients\EnrollmentState> $enrollmentStates
 * @var array<int, \Maguari\Server\Clients\HeartbeatStatus> $heartbeats
 * @var \Maguari\Server\Monitoring\Domain\DailyJobSummary $dailyJob
 * @var \Closure $partial
 */
use Maguari\Server\Clients\ClientsApi;
use Maguari\Server\Monitoring\Domain\CertificateExpiryRule;
use Maguari\Server\Monitoring\Domain\CheckOutcome;
use Maguari\Server\Monitoring\Domain\DailyJobState;
use Maguari\Shared\Protocol;

$utc = static fn (int $at): string => gmdate('Y-m-d H:i', $at) . ' UTC';
$lastRun = $dailyJob->lastRun;
$failures = [];
$certificateFailures = [];
?>
<h1>Maguari</h1>
<p>Signed in as <?= $e($administrator->name) ?> (<?= $e($administrator->email) ?>).</p>
<p><a href="/admin/projects">Projects</a></p>
<h2>Daily job</h2>
<p><?php if ($lastRun === null): ?>The daily job has never run.<?php
elseif ($lastRun->state === DailyJobState::Running): ?>Running since <?= $e($utc($lastRun->startedAt)) ?> (<?= $e($lastRun->trigger->value) ?>).<?php
elseif ($lastRun->state === DailyJobState::Killed): ?>The last run, started at <?= $e($utc($lastRun->startedAt)) ?> (<?= $e($lastRun->trigger->value) ?>), did not finish.<?php
elseif ($lastRun->state === DailyJobState::Failed): ?>The last run, started at <?= $e($utc($lastRun->startedAt)) ?> (<?= $e($lastRun->trigger->value) ?>), failed. The details are in the server's error log.<?php
else: ?><?php $took = (int) $lastRun->finishedAt - $lastRun->startedAt; ?>Last run: <?= $e($utc($lastRun->startedAt)) ?> (<?= $e($lastRun->trigger->value) ?>), took <?= $took ?> <?= $took === 1 ? 'second' : 'seconds' ?>.<?php
endif; ?></p>
<?php if ($dailyJob->lastScheduledRun === null): ?>
<p>No scheduled run yet. On the server, the maguari-server-daily-job timer runs the job every day at 06:00 UTC.</p>
<?php elseif ($dailyJob->overdue): ?>
<p><strong>The daily job is overdue:</strong> no scheduled run started in the last 25 hours. Check the maguari-server-daily-job timer on the server.</p>
<?php endif; ?>
<?php if ($dailyJob->lastSucceededRun !== null && $dailyJob->lastSucceededRun->id !== $lastRun?->id): ?>
<p>The results below are from the last successful run, at <?= $e($utc($dailyJob->lastSucceededRun->startedAt)) ?>.</p>
<?php endif; ?>
<form method="post" action="/admin/daily-job">
<?= $csrf ?>
<button type="submit">Run now</button>
</form>
<h2>Instances</h2>
<?php if ($instances === []): ?>
<p>No instances yet. Open a project and press Enroll next to an instance.</p>
<?php else: ?>
<table>
<thead><tr><th>Project</th><th>Instance</th><th>Zone</th><th>Enrollment</th><th>Heartbeat</th><th>Last heartbeat</th><th>Client version</th><th>Disk size</th><th>Certificates</th></tr></thead>
<tbody>
<?php foreach ($instances as $instance): ?>
<?php $heartbeat = $heartbeats[$instance->id] ?? null; ?>
<?php $diskSize = $dailyJob->diskSizeResults[$instance->id] ?? null; ?>
<?php if ($diskSize?->outcome === CheckOutcome::Fail) { $failures[] = [$instance, $diskSize]; } ?>
<?php
$certificates = $dailyJob->certificateResults[$instance->id] ?? [];
$worst = CheckOutcome::worst(array_map(static fn ($certificate) => $certificate->outcome, $certificates));

foreach ($certificates as $certificate) {
    if ($certificate->outcome === CheckOutcome::Fail) {
        $certificateFailures[] = [$instance, $certificate];
    }
}
?>
<tr><td><a href="/admin/projects/<?= $instance->projectId ?>"><?= $e($instance->gcpProjectId) ?></a></td><td><?= $e($instance->name) ?></td><td><?= $e($instance->zone) ?></td>
<?= $partial('heartbeat', ['enrollment' => $enrollmentStates[$instance->id], 'heartbeat' => $heartbeat]) ?>
<td><?= $e($heartbeat?->clientVersion ?? '') ?></td>
<td><?php if ($diskSize !== null): ?><?= $e($diskSize->outcome->label()) ?><?= $partial('info', ['text' => $diskSize->detail]) ?><?php endif; ?></td>
<td><?php if ($certificates !== []): ?><?= $e($worst === CheckOutcome::Pass ? sprintf('Pass (%d)', count($certificates)) : $worst->label()) ?><?= $partial('info', ['text' => implode("\n", array_map(static fn ($certificate) => $certificate->subject . ': ' . $certificate->detail, $certificates))]) ?><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<?php /* TEMPORARY (MVP): a fixed limit until Monitoring's heartbeat-age check (ClientsApi::LATE_AFTER_SECONDS). */ ?>
<p>Heartbeats are expected every <?= Protocol::HEARTBEAT_INTERVAL_SECONDS ?> seconds. A heartbeat older than <?= ClientsApi::LATE_AFTER_SECONDS ?> seconds is late. Reload the page to update.</p>
<p>Disk size compares each instance's boot disk with the filesystems its client reports on it. Certificates shows the worst result among the Let's Encrypt certificates each client reports, with the number checked when all pass; a certificate fails with fewer than <?= CertificateExpiryRule::MIN_DAYS_LEFT ?> days left. Hover over or tab to the i next to a result for details.</p>
<?php if ($failures !== []): ?>
<h3>Disk size failures</h3>
<ul>
<?php foreach ($failures as [$instance, $diskSize]): ?>
<li><?= $e($instance->gcpProjectId . '/' . $instance->name) ?>: <?= $e($diskSize->detail) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if ($certificateFailures !== []): ?>
<h3>Certificate failures</h3>
<ul>
<?php foreach ($certificateFailures as [$instance, $certificate]): ?>
<li><?= $e($instance->gcpProjectId . '/' . $instance->name . ': ' . $certificate->subject) ?>: <?= $e($certificate->detail) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php endif; ?>
<form method="post" action="/admin/logout">
<?= $csrf ?>
<button type="submit">Sign out</button>
</form>
