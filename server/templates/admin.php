<?php declare(strict_types=1);
/**
 * @var \Closure $e @var string $csrf @var \Maguari\Server\Access\Administrator $administrator
 * @var \Maguari\Server\Fleet\Instance[] $instances
 * @var \Maguari\Server\Notifications\Notification[] $notifications unresolved and not hidden by this administrator
 * @var array<int, \Maguari\Server\Clients\EnrollmentState> $enrollmentStates
 * @var array<int, \Maguari\Server\Monitoring\Domain\HeartbeatStatus> $heartbeats enrolled instances only
 * @var array<int, string> $clientVersions
 * @var \Maguari\Server\Monitoring\Domain\DailyJobSummary $dailyJob
 * @var array<int, list<\Maguari\Server\Monitoring\Domain\HttpCheckStatus>> $httpChecks by instance ID
 * @var \Closure $partial
 */
use Maguari\Server\Monitoring\Domain\CertificateExpiryRule;
use Maguari\Server\Monitoring\Domain\CheckOutcome;
use Maguari\Server\Monitoring\Domain\HeartbeatAgeRule;
use Maguari\Server\Monitoring\MonitoringApi;
use Maguari\Server\Notifications\NotificationsApi;

$utc = static fn (int $at): string => gmdate('Y-m-d H:i', $at) . ' UTC';
$lastRun = $dailyJob->lastRun;
$failures = [];
$certificateFailures = [];
?>
<h1>Maguari</h1>
<p>Signed in as <?= $e($administrator->name) ?> (<?= $e($administrator->email) ?>).</p>
<p><a href="/admin/projects">Projects</a> · <a href="/admin/email">Email</a> · <a href="/admin/logs">Logs</a></p>
<h2>Open incidents</h2>
<?php
$picked = [];

foreach ($instances as $instance) {
    $picked[$instance->id] = $instance;
}
?>
<?php if ($notifications === []): ?>
<p>None. Hidden ones and all past incidents are on the <a href="/admin/logs">Logs</a> screen.</p>
<?php else: ?>
<table>
<thead><tr><th>Opened</th><th>Instance</th><th>Check</th><th>Subject</th><th></th></tr></thead>
<tbody>
<?php foreach ($notifications as $notification): ?>
<?php $instance = $picked[$notification->instanceId] ?? null; ?>
<tr><td><?= $e($utc($notification->openedAt)) ?></td><td><?php if ($instance !== null): ?><a href="/admin/instances/<?= $instance->id ?>"><?= $e($instance->gcpProjectId . '/' . $instance->name) ?></a><?php else: ?>Instance <?= $notification->instanceId ?><?php endif; ?></td><td><?= $e(NotificationsApi::checkLabel($notification->checkName)) ?></td><td><?= $e($notification->subject) ?></td><td><form method="post" action="/admin/notifications/<?= $notification->id ?>/hide">
<?= $csrf ?>
<button type="submit">Hide</button>
</form></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<p>An incident leaves this list when it resolves, or for you when you hide it. Details are on each instance's page.</p>
<?php endif; ?>
<h2>Daily job</h2>
<?= $partial('daily-job-status', ['run' => $lastRun]) ?><?php if ($dailyJob->lastScheduledRun === null): ?>
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
<thead><tr><th>Project</th><th>Instance</th><th>Zone</th><th>Enrollment</th><th>Heartbeat</th><th>Last heartbeat</th><th>Client version</th><th>Disk size</th><th>Certificates</th><th>Checks</th></tr></thead>
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

$checks = $httpChecks[$instance->id] ?? [];
$worstCheck = CheckOutcome::worst(array_map(static fn ($status) => $status->outcome(), $checks));
?>
<tr><td><a href="/admin/projects/<?= $instance->projectId ?>"><?= $e($instance->gcpProjectId) ?></a></td><td><a href="/admin/instances/<?= $instance->id ?>"><?= $e($instance->name) ?></a></td><td><?= $e($instance->zone) ?></td>
<?= $partial('heartbeat', ['enrollment' => $enrollmentStates[$instance->id], 'heartbeat' => $heartbeat]) ?>
<td><?= $e($clientVersions[$instance->id] ?? '') ?></td>
<td><?php if ($diskSize !== null): ?><?= $e($diskSize->outcome->label()) ?><?= $partial('info', ['text' => $diskSize->detail]) ?><?php endif; ?></td>
<td><?php if ($certificates !== []): ?><?= $e($worst === CheckOutcome::Pass ? sprintf('Pass (%d)', count($certificates)) : $worst->label()) ?><?= $partial('info', ['text' => implode("\n", array_map(static fn ($certificate) => $certificate->subject . ($certificate->checkName === CertificateExpiryRule::REMOTE_CHECK_NAME ? ', served: ' : ', on the instance: ') . $certificate->detail, $certificates))]) ?><?php endif; ?></td>
<td><?php if ($checks !== []): ?><?= $e($worstCheck === CheckOutcome::Pass ? sprintf('Pass (%d)', count($checks)) : $worstCheck->label()) ?><?= $partial('info', ['text' => implode("\n", array_map(static fn ($status) => 'Web app ' . $status->check->url . ': ' . $status->outcome()->label() . '. ' . $status->detail(), $checks))]) ?><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<p>Heartbeats are expected every <?= MonitoringApi::EXPECTED_INTERVAL_SECONDS ?> seconds. A heartbeat older than <?= HeartbeatAgeRule::maxAgeSeconds(MonitoringApi::EXPECTED_INTERVAL_SECONDS) ?> seconds is late. Reload the page to update.</p>
<p>Disk size compares each instance's boot disk with the filesystems its client reports on it. Certificates shows the worst result among the Let's Encrypt certificates each client reports and the certificates its hostnames serve (set on the instance's page), with the number checked when all pass; a certificate fails with fewer than <?= CertificateExpiryRule::MIN_DAYS_LEFT ?> days left. Checks shows the worst current result of the web apps the server requests for each instance (set on the instance's page). Hover over or tab to the i next to a result for details.</p>
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
<li><?= $e($instance->gcpProjectId . '/' . $instance->name . ': ' . $certificate->subject . ($certificate->checkName === CertificateExpiryRule::REMOTE_CHECK_NAME ? ', served' : ', on the instance')) ?>: <?= $e($certificate->detail) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php endif; ?>
<form method="post" action="/admin/logout">
<?= $csrf ?>
<button type="submit">Sign out</button>
</form>
