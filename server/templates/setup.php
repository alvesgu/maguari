<?php declare(strict_types=1);
/**
 * @var \Closure $e
 * @var string $csrf
 * @var string $token
 * @var string $name
 * @var string $email
 * @var array<string, string> $errors
 */
$error = static fn (string $field): string => isset($errors[$field]) ? '<p>' . $e($errors[$field]) . '</p>' : ''; ?>
<h1>Set up Maguari</h1>
<p>Create the administrator account. You will sign in with this email address and password.</p>
<form method="post" action="/auth/setup">
<?= $csrf ?>
<input type="hidden" name="token" value="<?= $e($token) ?>">
<p><label for="name">Name</label><br>
<input id="name" name="name" value="<?= $e($name) ?>" maxlength="100" autocomplete="name" required></p>
<?= $error('name') ?>
<p><label for="email">Email</label><br>
<input id="email" name="email" type="email" value="<?= $e($email) ?>" autocomplete="email" required></p>
<?= $error('email') ?>
<p><label for="password">Password (at least 12 characters)</label><br>
<input id="password" name="password" type="password" minlength="12" maxlength="1024" autocomplete="new-password" required></p>
<?= $error('password') ?>
<p><label for="password_confirmation">Confirm password</label><br>
<input id="password_confirmation" name="password_confirmation" type="password" minlength="12" maxlength="1024" autocomplete="new-password" required></p>
<?= $error('password_confirmation') ?>
<p><button type="submit">Create administrator</button></p>
</form>
