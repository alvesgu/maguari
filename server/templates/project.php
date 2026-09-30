<?php declare(strict_types=1);
/** @var \Closure $e @var \Maguari\Server\Fleet\Project $project @var ?\Maguari\Server\Fleet\InstanceList $instances @var ?string $error */ ?>
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
<thead><tr><th>Name</th><th>Zone</th><th>Status</th><th>Machine type</th></tr></thead>
<tbody>
<?php foreach ($instances->instances as $instance): ?>
<tr><td><?= $e($instance->name) ?></td><td><?= $e($instance->zone) ?></td><td><?= $e($instance->status->label()) ?></td><td><?= $e($instance->machineType) ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<?php endif; ?>
