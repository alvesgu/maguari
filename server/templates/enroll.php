<?php declare(strict_types=1);
/** @var \Closure $e @var \Maguari\Server\Fleet\Project $project @var ?\Maguari\Server\Fleet\Instance $instance @var ?string $command @var ?int $expiresAt @var ?string $error */ ?>
<?php if ($instance === null || $command === null || $expiresAt === null): ?>
<h1>Enroll an instance</h1>
<p><?= $e((string) $error) ?></p>
<?php else: ?>
<h1>Enroll <?= $e($instance->name) ?></h1>
<p>Project <?= $e($project->gcpProjectId) ?>, zone <?= $e($instance->zone) ?>.</p>
<p>Run this command on the instance. The token works once, until <?= $e(gmdate('Y-m-d H:i', $expiresAt)) ?> UTC, and only for this instance. It is shown only on this page. Pressing Enroll again issues a new token and the earlier one stops working.</p>
<pre><code><?= $e($command) ?></code></pre>
<p>The client is not packaged yet. Until it is, run it from a source checkout of Maguari, as <code>client/bin/maguari-client</code>.</p>
<?php endif; ?>
<p><a href="/admin/projects/<?= $project->id ?>">Back to <?= $e($project->gcpProjectId) ?></a></p>
