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
 * @var \Maguari\Server\Monitoring\Domain\HttpCheckStatus[] $httpChecks
 * @var int $httpEgressBytes every remote HTTP check's estimated monthly egress together
 * @var string $httpUrl @var string $httpInterval @var ?string $httpError @var ?string $intervalError
 * @var ?string $notice the warning of the last change, shown once
 * @var \Maguari\Server\Monitoring\Domain\ClientCheckStatus[] $clientChecks
 * @var \Maguari\Server\Monitoring\Domain\ClientCheckSettings $clientCheckSettings what the client should have
 * @var ?\Maguari\Server\Clients\ReportedCheckSettings $reportedCheckSettings null before the client's first heartbeat
 * @var ?string $clientVersion @var string $unit @var ?string $unitError
 * @var string $localUrl @var string $hostName @var ?string $localHttpError
 * @var string $database @var ?string $databaseError
 * @var int $now
 */
use Maguari\Server\Clients\ReportedCheckSettings;
use Maguari\Server\Http\TimeAgo;
use Maguari\Server\Monitoring\Domain\CertificateExpiryRule;
use Maguari\Server\Monitoring\Domain\CertificateHostname;
use Maguari\Server\Monitoring\Domain\ClientCheck;
use Maguari\Server\Monitoring\Domain\ConfirmedFailures;
use Maguari\Server\Monitoring\Domain\HttpCheck;
use Maguari\Server\Monitoring\Domain\HttpCheckEgress;
use Maguari\Server\Monitoring\Domain\HttpCheckRule;
use Maguari\Server\Monitoring\MonitoringApi;
use Maguari\Server\Remediation\Domain\ResolutionRule;
use Maguari\Server\Remediation\RemediationApi;
use Maguari\Shared\DatabaseCheck;
use Maguari\Shared\LocalHttpCheck;
use Maguari\Shared\ServiceCheck;

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
<h2>Web apps</h2>
<p>The server requests each URL on its interval, as visitors do, and reads only the status of the answer: 2xx and 3xx pass (redirects are not followed), anything else fails. A failed request is tried once more <?= HttpCheckRule::RETRY_AFTER_SECONDS ?> seconds later before it counts. A new URL is checked within a minute.</p>
<p>Each request costs the server about <?= intdiv(HttpCheckEgress::BYTES_PER_CHECK, 1024) ?> KB of egress, which the free tier limits to 1 GB a month: <?= HttpCheckEgress::megabytes(HttpCheckEgress::monthlyBytes(HttpCheck::DEFAULT_INTERVAL_MINUTES * 60)) ?> MB a month at the default <?= HttpCheck::DEFAULT_INTERVAL_MINUTES ?> minutes, <?= HttpCheckEgress::megabytes(HttpCheckEgress::monthlyBytes(60)) ?> MB at 1 minute. All web app checks together use about <?= HttpCheckEgress::megabytes($httpEgressBytes) ?> MB a month. Up to <?= HttpCheckEgress::megabytes(HttpCheckEgress::MONTHLY_BUDGET_BYTES) ?> MB is suggested; above it a change saves with a warning, and above <?= HttpCheckEgress::megabytes(HttpCheckEgress::MONTHLY_LIMIT_BYTES) ?> MB it is refused.</p>
<?php if ($notice !== null): ?>
<p><?= $e($notice) ?></p>
<?php endif; ?>
<?php if ($intervalError !== null): ?>
<p><?= $e($intervalError) ?></p>
<?php endif; ?>
<?php if ($httpChecks === []): ?>
<p>None yet.</p>
<?php else: ?>
<table>
<thead><tr><th>URL</th><th>Result</th><th>Checked</th><th>Every (minutes)</th><th></th></tr></thead>
<tbody>
<?php foreach ($httpChecks as $status): ?>
<?php $checkedAt = $status->state?->checkedAt; ?>
<tr><td><?= $e($status->check->url) ?></td><td><?= $e($status->outcome()->label()) ?><?= $partial('info', ['text' => $status->detail()]) ?></td><td><?= $checkedAt === null ? '' : $e(TimeAgo::format(max(0, $now - $checkedAt))) ?></td><td><form method="post" action="/admin/instances/<?= $instance->id ?>/http-checks/<?= $status->check->id ?>/interval">
<?= $csrf ?>
<input name="interval" type="number" min="<?= HttpCheck::MIN_INTERVAL_MINUTES ?>" max="<?= HttpCheck::MAX_INTERVAL_MINUTES ?>" value="<?= $status->check->intervalMinutes() ?>" aria-label="Interval in minutes for <?= $e($status->check->url) ?>" required>
<button type="submit">Change</button>
</form></td><td><form method="post" action="/admin/instances/<?= $instance->id ?>/http-checks/<?= $status->check->id ?>/remove">
<?= $csrf ?>
<button type="submit">Remove</button>
</form></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<?php if (count($httpChecks) < HttpCheck::MAX_PER_INSTANCE): ?>
<?php if ($httpError !== null): ?>
<p><?= $e($httpError) ?></p>
<?php endif; ?>
<form method="post" action="/admin/instances/<?= $instance->id ?>/http-checks">
<?= $csrf ?>
<p><label for="url">URL</label><br>
<input id="url" name="url" type="url" value="<?= $e($httpUrl) ?>" placeholder="https://www.example.com/" autocomplete="off" spellcheck="false" required></p>
<p><label for="interval">Every (minutes, <?= HttpCheck::MIN_INTERVAL_MINUTES ?> to <?= HttpCheck::MAX_INTERVAL_MINUTES ?>)</label><br>
<input id="interval" name="interval" type="number" min="<?= HttpCheck::MIN_INTERVAL_MINUTES ?>" max="<?= HttpCheck::MAX_INTERVAL_MINUTES ?>" value="<?= $e($httpInterval) ?>"></p>
<p><button type="submit">Add web app</button></p>
</form>
<?php else: ?>
<p>This instance has the most web app checks allowed (<?= HttpCheck::MAX_PER_INSTANCE ?>). Remove one to add another.</p>
<?php endif; ?>
<h2>Checks on the instance</h2>
<p>The client runs these before every heartbeat and reports the results. A check fails after <?= ConfirmedFailures::FAILURES_TO_FAIL ?> failed results in a row, so a restart in progress opens no incident. The client gets a change with the response to its next heartbeat.</p>
<?php if ($reportedCheckSettings === null): ?>
<p>The client has not sent a heartbeat yet. It gets these checks with its first one.</p>
<?php elseif (!$reportedCheckSettings->supported): ?>
<p>This client<?= $clientVersion === null ? '' : ' (version ' . $e($clientVersion) . ')' ?> does not run checks. It needs version <?= ReportedCheckSettings::SINCE_CLIENT_VERSION ?> or later.</p>
<?php elseif ($reportedCheckSettings->version !== $clientCheckSettings->version): ?>
<p>Waiting for the client to pick up the latest change with its next heartbeat.</p>
<?php endif; ?>
<?php
$services = array_values(array_filter($clientChecks, static fn ($status): bool => $status->check->type === ServiceCheck::TYPE));
$localHttp = array_values(array_filter($clientChecks, static fn ($status): bool => $status->check->type === LocalHttpCheck::TYPE));
$databases = array_values(array_filter($clientChecks, static fn ($status): bool => $status->check->type === DatabaseCheck::TYPE));
// For clients that store a newer kind of check but skip it.
$tooOld = static function (array $statuses, string $type, string $what) use ($e, $clientVersion): string {
    $needed = $statuses === [] ? null : ReportedCheckSettings::versionNeeded($type, $clientVersion);

    return $needed === null ? '' : '<p>This client (version ' . $e((string) $clientVersion) . ') skips ' . $what . '. It needs version ' . $needed . " or later.</p>\n";
};
$row = static function ($status, string $first) use ($e, $partial, $csrf, $instance, $now): string {
    $checkedAt = $status->state?->checkedAt;

    return '<tr>' . $first . '<td>' . $e($status->outcome()->label()) . $partial('info', ['text' => $status->detail()]) . '</td><td>'
        . ($checkedAt === null ? '' : $e(TimeAgo::format(max(0, $now - $checkedAt)))) . '</td><td><form method="post" action="/admin/instances/' . $instance->id . '/client-checks/' . $status->check->id . "/remove\">\n"
        . $csrf . "\n<button type=\"submit\">Remove</button>\n</form></td></tr>\n";
};
?>
<h3>Services</h3>
<p>The client asks systemd whether each service is active. A service that is not, or that systemd does not have, fails.</p>
<?php if ($services === []): ?>
<p>None yet.</p>
<?php else: ?>
<table>
<thead><tr><th>Service</th><th>Result</th><th>Checked</th><th></th></tr></thead>
<tbody>
<?php foreach ($services as $status): ?>
<?= $row($status, '<td>' . $e($status->check->target) . '</td>') ?>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<?php if (count($services) < ClientCheck::MAX_SERVICES_PER_INSTANCE): ?>
<?php if ($unitError !== null): ?>
<p><?= $e($unitError) ?></p>
<?php endif; ?>
<form method="post" action="/admin/instances/<?= $instance->id ?>/services">
<?= $csrf ?>
<p><label for="unit">Service</label><br>
<input id="unit" name="unit" type="text" value="<?= $e($unit) ?>" placeholder="nginx.service" autocomplete="off" spellcheck="false" required></p>
<p><button type="submit">Add service</button></p>
</form>
<?php else: ?>
<p>This instance has the most service checks allowed (<?= ClientCheck::MAX_SERVICES_PER_INSTANCE ?>). Remove one to add another.</p>
<?php endif; ?>
<h3>Local HTTP</h3>
<p>The client requests each URL on the instance itself (127.0.0.1, [::1] or localhost) and reads only the status of the answer: 2xx and 3xx pass, redirects are not followed. A host name, when set, is sent as the Host header and is the name the certificate must match, so a site behind nginx's server_name is reached as visitors reach it. No egress: the results ride in the heartbeat.</p>
<?= $tooOld($localHttp, LocalHttpCheck::TYPE, 'local HTTP checks') ?>
<?php if ($localHttp === []): ?>
<p>None yet.</p>
<?php else: ?>
<table>
<thead><tr><th>URL</th><th>Host name</th><th>Result</th><th>Checked</th><th></th></tr></thead>
<tbody>
<?php foreach ($localHttp as $status): ?>
<?= $row($status, '<td>' . $e($status->check->target) . '</td><td>' . $e($status->check->hostName) . '</td>') ?>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<?php if (count($localHttp) < ClientCheck::MAX_LOCAL_HTTP_PER_INSTANCE): ?>
<?php if ($localHttpError !== null): ?>
<p><?= $e($localHttpError) ?></p>
<?php endif; ?>
<form method="post" action="/admin/instances/<?= $instance->id ?>/local-http">
<?= $csrf ?>
<p><label for="local_url">URL</label><br>
<input id="local_url" name="local_url" type="text" value="<?= $e($localUrl) ?>" placeholder="http://127.0.0.1:8000/health" autocomplete="off" spellcheck="false" required></p>
<p><label for="host_name">Host name (optional; required for https://)</label><br>
<input id="host_name" name="host_name" type="text" value="<?= $e($hostName) ?>" placeholder="geonode.example.com" autocomplete="off" spellcheck="false"></p>
<p><button type="submit">Add local HTTP check</button></p>
</form>
<?php else: ?>
<p>This instance has the most local HTTP checks allowed (<?= ClientCheck::MAX_LOCAL_HTTP_PER_INSTANCE ?>). Remove one to add another.</p>
<?php endif; ?>
<h3>Databases</h3>
<p>The client logs in to each database and runs SELECT 1, with <?= DatabaseCheck::TIMEOUT_SECONDS ?> seconds to connect and <?= DatabaseCheck::TIMEOUT_SECONDS ?> for the query. Only its name is set here: how to log in is set up on the instance, in a file only the client's user can read, and never reaches this server. On the instance, run <code>sudo -u maguari-client maguari-client set-database &lt;name&gt; --type=postgresql --host=127.0.0.1 --database=&lt;database&gt; --user=&lt;user&gt;</code> (or <code>--type=mysql</code> for MySQL and MariaDB); it asks for the password. Use a user that can only log in, for example one created with <code>CREATE ROLE maguari_check LOGIN PASSWORD '...'</code> and <code>GRANT CONNECT ON DATABASE geonode TO maguari_check</code>.</p>
<?= $tooOld($databases, DatabaseCheck::TYPE, 'database checks') ?>
<?php if ($databases === []): ?>
<p>None yet.</p>
<?php else: ?>
<table>
<thead><tr><th>Database</th><th>Result</th><th>Checked</th><th></th></tr></thead>
<tbody>
<?php foreach ($databases as $status): ?>
<?= $row($status, '<td>' . $e($status->check->target) . '</td>') ?>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<?php if (count($databases) < ClientCheck::MAX_DATABASES_PER_INSTANCE): ?>
<?php if ($databaseError !== null): ?>
<p><?= $e($databaseError) ?></p>
<?php endif; ?>
<form method="post" action="/admin/instances/<?= $instance->id ?>/databases">
<?= $csrf ?>
<p><label for="database">Name on the instance</label><br>
<input id="database" name="database" type="text" value="<?= $e($database) ?>" placeholder="geonode" autocomplete="off" spellcheck="false" required></p>
<p><button type="submit">Add database</button></p>
</form>
<?php else: ?>
<p>This instance has the most database checks allowed (<?= ClientCheck::MAX_DATABASES_PER_INSTANCE ?>). Remove one to add another.</p>
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
