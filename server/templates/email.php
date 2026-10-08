<?php declare(strict_types=1);
/**
 * @var \Closure $e @var string $csrf @var \Closure $partial
 * @var ?\Maguari\Server\Notifications\SmtpSettingsSummary $settings
 * @var string $recipient the signed-in administrator's address
 * @var array{host: string, port: string, username: string, from_address: string} $form
 * @var array<string, string> $errors
 * @var ?string $seedHost the seed file's SMTP host, when it has an [smtp] section and nothing is stored
 * @var ?string $seedProblem why the seed file could not be read
 * @var string[] $seedErrors why the seed file's SMTP settings were not used
 */
use Maguari\Server\Notifications\NotificationsApi;
use Maguari\Server\Notifications\SmtpEncryption;
use Maguari\Server\Notifications\SmtpSettingsRules;

$error = static fn (string $field): string => isset($errors[$field]) ? '<p>' . $e($errors[$field]) . '</p>' : '';
?>
<h1>Email</h1>
<p><a href="/admin">Back to the dashboard</a></p>
<p>Maguari sends email through an SMTP server, for example Gmail (smtp.gmail.com) or the Google Workspace SMTP relay (smtp-relay.gmail.com). Compute Engine blocks outbound port <?= SmtpSettingsRules::BLOCKED_PORT ?>, so use port <?= SmtpSettingsRules::DEFAULT_PORT ?>.</p>
<?php if ($recipient !== ''): ?>
<p>Emails go to <?= $e($recipient) ?>, the address you signed in with.</p>
<?php endif; ?>
<h2>Saved settings</h2>
<?php if ($settings === null): ?>
<p>Email is not set up yet.</p>
<?php if ($seedHost !== null): ?>
<form method="post" action="/admin/email/import-seed">
<?= $csrf ?>
<p>The seed file has SMTP settings<?= $seedHost !== '' ? ' for ' . $e($seedHost) : '' ?>. <button type="submit">Use the SMTP settings from the seed file</button></p>
</form>
<?php elseif ($seedProblem !== null): ?>
<p>The seed file could not be read: <?= $e($seedProblem) ?></p>
<?php endif; ?>
<?php if ($seedErrors !== []): ?>
<p>The seed file's SMTP settings were not used:</p>
<ul>
<?php foreach ($seedErrors as $seedError): ?>
<li><?= $e($seedError) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php else: ?>
<table>
<tbody>
<tr><th>Host</th><td><?= $e($settings->host) ?></td></tr>
<tr><th>Port</th><td><?= $settings->port ?></td></tr>
<tr><th>Encryption</th><td><?= $e($settings->encryption()->label()) ?></td></tr>
<tr><th>Username</th><td><?= $settings->username === '' ? 'None (no sign-in)' : $e($settings->username) ?></td></tr>
<tr><th>Password</th><td><?= !$settings->hasPassword ? 'None' : ($settings->passwordReadable ? 'Stored (encrypted)' : $e(NotificationsApi::PASSWORD_UNREADABLE)) ?></td></tr>
<tr><th>From address</th><td><?= $e($settings->fromAddress) ?></td></tr>
<tr><th>Saved</th><td><?= $e(gmdate('Y-m-d H:i', $settings->updatedAt)) ?> UTC</td></tr>
</tbody>
</table>
<?php endif; ?>
<h2><?= $settings === null ? 'Set up' : 'Change' ?></h2>
<p>Port <?= SmtpEncryption::IMPLICIT_TLS_PORT ?> uses TLS from the start; every other port must offer STARTTLS. Only localhost, for a development server, may be unencrypted.</p>
<form method="post" action="/admin/email">
<?= $csrf ?>
<p><label for="host">Host</label><br>
<input id="host" name="host" type="text" value="<?= $e($form['host']) ?>" placeholder="smtp.gmail.com" autocomplete="off" spellcheck="false" required></p>
<?= $error('host') ?>
<p><label for="port">Port</label><br>
<input id="port" name="port" type="text" inputmode="numeric" value="<?= $e($form['port']) ?>" autocomplete="off"></p>
<?= $error('port') ?>
<p><label for="username">Username (empty for a server that needs no sign-in)</label><br>
<input id="username" name="username" type="text" value="<?= $e($form['username']) ?>" maxlength="<?= SmtpSettingsRules::USERNAME_MAX_BYTES ?>" autocomplete="off" spellcheck="false"></p>
<?= $error('username') ?>
<p><label for="password">Password<?= $settings?->hasPassword ? ' (leave empty to keep the stored one)' : '' ?></label><br>
<input id="password" name="password" type="password" maxlength="<?= SmtpSettingsRules::PASSWORD_MAX_BYTES ?>" autocomplete="new-password"></p>
<?= $error('password') ?>
<p><label for="from_address">From address</label><br>
<input id="from_address" name="from_address" type="email" value="<?= $e($form['from_address']) ?>" placeholder="alerts@example.com" autocomplete="off" spellcheck="false" required></p>
<?= $error('from_address') ?>
<p><button type="submit">Save</button></p>
</form>
