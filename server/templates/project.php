<?php declare(strict_types=1);
/** @var \Closure $e @var \Maguari\Server\Fleet\Project $project @var ?\Maguari\Server\Fleet\InstanceList $instances @var array<string, \Maguari\Server\Clients\EnrollmentState> $enrollmentStates @var array<string, \Maguari\Server\Clients\HeartbeatStatus> $heartbeats @var array<string, int> $instanceIds picked instances' IDs @var ?string $error @var string $csrf @var \Closure $partial */ ?>
<h1><?= $e($project->gcpProjectId) ?></h1>
<p><a href="/admin/projects">Back to projects</a></p>
<h2>Instances</h2>
<?php if ($error !== null): ?>
<p><?= $e($error) ?></p>
<?php elseif ($instances !== null): ?>
<?php if ($instances->unreachableZones !== []): ?>
<p>Some zones could not be reached: <?= $e(implode(', ', $instances->unreachableZones)) ?>.</p>
<?php endif; ?>
<?php if ($instances->truncated): ?>
<p>This project has more instances than Maguari lists. Only the first <?= count($instances->instances) ?> are shown.</p>
<?php endif; ?>
<?php if ($instances->instances === []): ?>
<p>No instances in this project.</p>
<?php else: ?>
<table>
<thead><tr><th>Name</th><th>Zone</th><th>Status</th><th>Machine type</th><th>Enrollment</th><th>Heartbeat</th><th>Last heartbeat</th><th></th></tr></thead>
<tbody>
<?php foreach ($instances->instances as $instance): ?>
<?php $key = $instance->zone . '/' . $instance->name; ?>
<?php $enrollment = $enrollmentStates[$key] ?? \Maguari\Server\Clients\EnrollmentState::NotEnrolled; ?>
<tr><td><?php if (isset($instanceIds[$key])): ?><a href="/admin/instances/<?= $instanceIds[$key] ?>"><?= $e($instance->name) ?></a><?php else: ?><?= $e($instance->name) ?><?php endif; ?></td><td><?= $e($instance->zone) ?></td><td><?= $e($instance->status->label()) ?></td><td><?= $e($instance->machineType) ?></td>
<?= $partial('heartbeat', ['enrollment' => $enrollment, 'heartbeat' => $heartbeats[$key] ?? null]) ?>
<td><form method="post" action="/admin/projects/<?= $project->id ?>/instances"><?= $csrf ?><input type="hidden" name="zone" value="<?= $e($instance->zone) ?>"><input type="hidden" name="name" value="<?= $e($instance->name) ?>"><button type="submit"><?= $enrollment === \Maguari\Server\Clients\EnrollmentState::Enrolled ? 'Re-enroll' : 'Enroll' ?></button></form></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<?php endif; ?>
