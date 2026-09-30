<?php declare(strict_types=1);
/** @var \Closure $e @var string $csrf @var \Maguari\Server\Access\Administrator $administrator */ ?>
<h1>Maguari</h1>
<p>Signed in as <?= $e($administrator->name) ?> (<?= $e($administrator->email) ?>).</p>
<p><a href="/admin/projects">Projects</a></p>
<form method="post" action="/admin/logout">
<?= $csrf ?>
<button type="submit">Sign out</button>
</form>
