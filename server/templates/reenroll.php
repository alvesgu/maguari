<?php declare(strict_types=1);
/** @var \Closure $e @var string $csrf @var \Maguari\Server\Fleet\Project $project @var \Maguari\Server\Fleet\Instance $instance */ ?>
<h1>Re-enroll <?= $e($instance->name) ?></h1>
<p>Project <?= $e($project->gcpProjectId) ?>, zone <?= $e($instance->zone) ?>.</p>
<p><?= $e($instance->name) ?> is already enrolled. Re-enrolling issues a new token, and the instance's client will need to enroll again with it. The current client keeps working until the new token is used, then it is replaced.</p>
<form method="post" action="/admin/projects/<?= $project->id ?>/instances"><?= $csrf ?><input type="hidden" name="zone" value="<?= $e($instance->zone) ?>"><input type="hidden" name="name" value="<?= $e($instance->name) ?>"><input type="hidden" name="confirm" value="re-enroll"><button type="submit">Re-enroll</button></form>
<p><a href="/admin/projects/<?= $project->id ?>">Cancel</a></p>
