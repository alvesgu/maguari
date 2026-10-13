<?php declare(strict_types=1);
/**
 * @var \Closure $e @var string $csrf @var \Closure $partial
 * @var \Maguari\Server\Fleet\Instance $instance
 * @var \Maguari\Server\Remediation\Domain\InstanceIncidents $incidents
 * @var \Maguari\Server\Monitoring\Domain\CheckResult[] $certificates from $resultsRun
 * @var ?\Maguari\Server\Monitoring\Domain\DailyJobRun $lastRun the daily job's latest run, whatever its state
 * @var ?\Maguari\Server\Monitoring\Domain\DailyJobRun $resultsRun the last successful run
 * @var \Maguari\Server\Monitoring\Domain\CertificateHostname[] $hostnames
 * @var string[] $suggestions
 * @var string $hostname @var ?string $error
 */
use Maguari\Server\Monitoring\Domain\CertificateExpiryRule;
use Maguari\Server\Monitoring\Domain\CertificateHostname;
use Maguari\Server\Monitoring\MonitoringApi;
use Maguari\Server\Remediation\Domain\ResolutionRule;
use Maguari\Server\Remediation\RemediationApi;

$utc = static fn (int $at): string => gmdate('Y-m-d H:i', $at) . ' UTC';
?>
<h1><?= $e($instance->name) ?></h1>
<p><a href="/admin">Back to the dashboard</a> · Project <a href="/admin/projects/<?= $instance->projectId ?>"><?= $e($instance->gcpProjectId) ?></a> · Zone <?= $e($instance->zone) ?></p>
<h2>Incidents</h2>
<p>An incident opens when a check fails and resolves after the check has passed for <?= intdiv(ResolutionRule::RESOLVE_AFTER_SECONDS, 60) ?> minutes.</p>
<?php if ($incidents->open === []): ?>
<p>No open incidents.</p>
<?php else: ?>
<table>
<thead><tr><th>Check</th><th>Subject</th><th>Opened</th><th>State</th></tr></thead>
<tbody>
<?php foreach ($incidents->open as $incident): ?>
<tr><td><?= $e(MonitoringApi::checkLabel($incident->key->checkName)) ?></td><td><?= $e($incident->key->subject) ?></td><td><?= $e($utc($incident->openedAt)) ?></td><td><?= $incident->passingSince === null ? 'Failing' : $e('Passing since ' . $utc($incident->passingSince)) ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<?php if ($incidents->resolved !== []): ?>
<h3>Resolved incidents</h3>
<p>The latest <?= RemediationApi::RESOLVED_SHOWN ?> at most, newest first.</p>
<table>
<thead><tr><th>Check</th><th>Subject</th><th>Opened</th><th>Resolved</th><th>Resolution</th></tr></thead>
<tbody>
<?php foreach ($incidents->resolved as $incident): ?>
<tr><td><?= $e(MonitoringApi::checkLabel($incident->key->checkName)) ?></td><td><?= $e($incident->key->subject) ?></td><td><?= $e($utc($incident->openedAt)) ?></td><td><?= $e($utc((int) $incident->resolvedAt)) ?></td><td><?php if ($incident->resolution !== null): ?><?= $e($incident->resolution->label()) ?><?= $partial('info', ['text' => $incident->resolution->explanation()]) ?><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<h2>Certificates</h2>
<?= $partial('daily-job-status', ['run' => $lastRun]) ?>
<form method="post" action="/admin/daily-job">
<?= $csrf ?>
<input type="hidden" name="instance_id" value="<?= $instance->id ?>">
<p><button type="submit">Run now</button> Runs the daily job for every instance, as on the dashboard, then comes back here.</p>
</form>
<?php if ($resultsRun === null): ?>
<p>No results yet: the daily job has not finished a run.</p>
<?php elseif ($certificates === []): ?>
<p>The last successful run, at <?= $e(gmdate('Y-m-d H:i', $resultsRun->startedAt)) ?> UTC, found no certificates for this instance.</p>
<?php else: ?>
<p>From the last successful run, at <?= $e(gmdate('Y-m-d H:i', $resultsRun->startedAt)) ?> UTC.</p>
<table>
<thead><tr><th>Certificate</th><th>Checked</th><th>Result</th></tr></thead>
<tbody>
<?php foreach ($certificates as $certificate): ?>
<tr><td><?= $e($certificate->subject) ?></td><td><?= $certificate->checkName === CertificateExpiryRule::REMOTE_CHECK_NAME ? 'Served on port ' . CertificateHostname::PORT : 'On the instance' ?></td><td><?= $e($certificate->outcome->label()) ?><?= $partial('info', ['text' => $certificate->detail]) ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<h2>Hostnames checked remotely</h2>
<p>The daily job connects to each hostname on port <?= CertificateHostname::PORT ?>, as visitors do, and checks the certificate it serves. This catches a renewed certificate that the web server never loaded. A new hostname is checked on the next run, or when you press Run now on the dashboard.</p>
<?php if ($hostnames === []): ?>
<p>None yet.</p>
<?php else: ?>
<table>
<thead><tr><th>Hostname</th><th>Added (UTC)</th><th></th></tr></thead>
<tbody>
<?php foreach ($hostnames as $known): ?>
<tr><td><?= $e($known->hostname) ?></td><td><?= $e(gmdate('Y-m-d H:i', $known->addedAt)) ?></td><td><form method="post" action="/admin/instances/<?= $instance->id ?>/certificate-hostnames/<?= $known->id ?>/remove">
<?= $csrf ?>
<button type="submit">Remove</button>
</form></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<?php if (count($hostnames) < CertificateHostname::MAX_PER_INSTANCE): ?>
<?php if ($error !== null): ?>
<p><?= $e($error) ?></p>
<?php endif; ?>
<form method="post" action="/admin/instances/<?= $instance->id ?>/certificate-hostnames">
<?= $csrf ?>
<p><label for="hostname">Hostname</label><br>
<input id="hostname" name="hostname" type="text" value="<?= $e($hostname) ?>" placeholder="www.example.com" autocomplete="off" spellcheck="false" required></p>
<p><button type="submit">Add hostname</button></p>
</form>
<?php if ($suggestions !== []): ?>
<p>Certificates on this instance that are not checked remotely yet:</p>
<ul>
<?php foreach ($suggestions as $suggestion): ?>
<li><form method="post" action="/admin/instances/<?= $instance->id ?>/certificate-hostnames">
<?= $csrf ?>
<input type="hidden" name="hostname" value="<?= $e($suggestion) ?>">
<?= $e($suggestion) ?> <button type="submit">Add</button>
</form></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php else: ?>
<p>This instance has the most hostnames allowed (<?= CertificateHostname::MAX_PER_INSTANCE ?>). Remove one to add another.</p>
<?php endif; ?>
