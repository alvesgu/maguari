<?php declare(strict_types=1);
/**
 * @var \Closure $e @var string $csrf @var \Maguari\Server\Access\Administrator $administrator
 * @var \Maguari\Server\Fleet\Instance[] $instances
 * @var array<int, \Maguari\Server\Clients\EnrollmentState> $enrollmentStates
 * @var array<int, \Maguari\Server\Clients\HeartbeatStatus> $heartbeats
 * @var \Closure $partial
 */ ?>
<h1>Maguari</h1>
<p>Signed in as <?= $e($administrator->name) ?> (<?= $e($administrator->email) ?>).</p>
<p><a href="/admin/projects">Projects</a></p>
<h2>Instances</h2>
<?php if ($instances === []): ?>
<p>No instances yet. Open a project and press Enroll next to an instance.</p>
<?php else: ?>
<table>
<thead><tr><th>Project</th><th>Instance</th><th>Zone</th><th>Enrollment</th><th>Heartbeat</th><th>Last heartbeat</th><th>Client version</th></tr></thead>
<tbody>
<?php foreach ($instances as $instance): ?>
<?php $heartbeat = $heartbeats[$instance->id] ?? null; ?>
<tr><td><a href="/admin/projects/<?= $instance->projectId ?>"><?= $e($instance->gcpProjectId) ?></a></td><td><?= $e($instance->name) ?></td><td><?= $e($instance->zone) ?></td>
<?= $partial('heartbeat', ['enrollment' => $enrollmentStates[$instance->id], 'heartbeat' => $heartbeat]) ?>
<td><?= $e($heartbeat?->clientVersion ?? '') ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<p>Heartbeats are expected every minute. A heartbeat older than 90 seconds is late. Reload the page to update.</p>
<?php endif; ?>
<form method="post" action="/admin/logout">
<?= $csrf ?>
<button type="submit">Sign out</button>
</form>
