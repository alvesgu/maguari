<?php declare(strict_types=1);
/** @var \Closure $e @var string $csrf @var string $email @var ?string $error */ ?>
<h1>Sign in to Maguari</h1>
<?php if ($error !== null): ?>
<p><?= $e($error) ?></p>
<?php endif; ?>
<form method="post" action="/auth/login">
<?= $csrf ?>
<p><label for="email">Email</label><br>
<input id="email" name="email" type="email" value="<?= $e($email) ?>" autocomplete="username" required></p>
<p><label for="password">Password</label><br>
<input id="password" name="password" type="password" autocomplete="current-password" required></p>
<p><button type="submit">Sign in</button></p>
</form>
