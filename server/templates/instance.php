<?php declare(strict_types=1);
/**
 * @var \Closure $e @var string $csrf @var \Closure $partial
 * @var \Maguari\Server\Fleet\Instance $instance
 * @var \Maguari\Server\Monitoring\Domain\CheckResult[] $certificates from $resultsRun
 * @var ?\Maguari\Server\Monitoring\Domain\DailyJobRun $resultsRun
 * @var \Maguari\Server\Monitoring\Domain\CertificateHostname[] $hostnames
 * @var string[] $suggestions
 * @var string $hostname @var ?string $error
 */
use Maguari\Server\Monitoring\Domain\CertificateExpiryRule;
use Maguari\Server\Monitoring\Domain\CertificateHostname;
?>
<h1><?= $e($instance->name) ?></h1>
<p><a href="/admin">Back to the dashboard</a> · Project <a href="/admin/projects/<?= $instance->projectId ?>"><?= $e($instance->gcpProjectId) ?></a> · Zone <?= $e($instance->zone) ?></p>
<h2>Certificates</h2>
<?php if ($resultsRun === null): ?>
<p>No results yet: the daily job has not finished a run.</p>
<?php elseif ($certificates === []): ?>
<p>The last daily job, at <?= $e(gmdate('Y-m-d H:i', $resultsRun->startedAt)) ?> UTC, found no certificates for this instance.</p>
<?php else: ?>
<p>From the last daily job, at <?= $e(gmdate('Y-m-d H:i', $resultsRun->startedAt)) ?> UTC.</p>
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
