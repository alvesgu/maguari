<?php declare(strict_types=1);
/**
 * @var \Closure $e
 * @var \Maguari\Server\Notifications\LogPage $page
 * @var array<int, string> $instanceNames picked instances' "project/name" by ID
 */
use Maguari\Server\Notifications\NotificationsApi;

$utc = static fn (int $at): string => gmdate('Y-m-d H:i', $at) . ' UTC';
?>
<h1>Logs</h1>
<p><a href="/admin">Back to the dashboard</a></p>
<p>Every incident's notification, newest first, whether hidden or not and resolved or not.</p>
<?php if ($page->notifications === []): ?>
<p>No notifications yet.</p>
<?php else: ?>
<table>
<thead><tr><th>Opened</th><th>Instance</th><th>Check</th><th>Subject</th><th>Resolved</th><th>Hidden</th></tr></thead>
<tbody>
<?php foreach ($page->notifications as $notification): ?>
<tr><td><?= $e($utc($notification->openedAt)) ?></td><td><?php if (isset($instanceNames[$notification->instanceId])): ?><a href="/admin/instances/<?= $notification->instanceId ?>"><?= $e($instanceNames[$notification->instanceId]) ?></a><?php else: ?>Instance <?= $notification->instanceId ?><?php endif; ?></td><td><?= $e(NotificationsApi::checkLabel($notification->checkName)) ?></td><td><?= $e($notification->subject) ?></td><td><?= $notification->resolvedAt === null ? 'Open' : $e($utc($notification->resolvedAt)) ?></td><td><?= $notification->hidden ? 'Hidden by you' : '' ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<?php if ($page->hasNewer || $page->olderBefore !== null): ?>
<p><?php if ($page->hasNewer): ?><a href="/admin/logs<?= $page->newerBefore === null ? '' : '?before=' . $page->newerBefore ?>">Newer</a><?php endif; ?><?php if ($page->hasNewer && $page->olderBefore !== null): ?> · <?php endif; ?><?php if ($page->olderBefore !== null): ?><a href="/admin/logs?before=<?= $page->olderBefore ?>">Older</a><?php endif; ?></p>
<?php endif; ?>
