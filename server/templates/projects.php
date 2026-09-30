<?php declare(strict_types=1);
/** @var \Closure $e @var string $csrf @var \Maguari\Server\Fleet\Project[] $projects @var string $projectId @var ?string $error */ ?>
<h1>Projects</h1>
<p><a href="/admin">Back to the dashboard</a></p>
<?php if ($projects === []): ?>
<p>No projects yet.</p>
<?php else: ?>
<table>
<thead><tr><th>Project ID</th><th>Added (UTC)</th></tr></thead>
<tbody>
<?php foreach ($projects as $project): ?>
<tr><td><a href="/admin/projects/<?= $project->id ?>"><?= $e($project->gcpProjectId) ?></a></td><td><?= $e(gmdate('Y-m-d H:i', $project->createdAt)) ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<h2>Add a project</h2>
<p>Maguari checks that it can list instances in the project before adding it.</p>
<?php if ($error !== null): ?>
<p><?= $e($error) ?></p>
<?php endif; ?>
<form method="post" action="/admin/projects">
<?= $csrf ?>
<p><label for="project_id">Project ID</label><br>
<input id="project_id" name="project_id" type="text" value="<?= $e($projectId) ?>" autocomplete="off" spellcheck="false" required></p>
<p><button type="submit">Add project</button></p>
</form>
