<?php declare(strict_types=1);
/**
 * @var \Closure $e @var \Closure $partial
 * @var \Maguari\Server\Notifications\LogPage $page
 * @var array<int, string> $instanceNames picked instances' "project/name" by ID
 * @var list<\Maguari\Server\Remediation\Domain\Action> $actions
 * @var ?int $actionsBefore
 */
use Maguari\Server\Notifications\NotificationsApi;
use Maguari\Server\Remediation\RemediationApi;

$utc = static fn (int $at): string => gmdate('Y-m-d H:i', $at) . ' UTC';
?>
<h1>Logs</h1>
<p><a href="/admin">Back to the dashboard</a></p>
<p>Every incident's notification, newest first, whether hidden or not and resolved or not.</p>
<?php if ($page->notifications === []): ?>
<p>No notifications yet.</p>
<?php else: ?>
<table>
<thead><tr><th>Opened</th><th>Instance</th><th>Check</th><th>Subject</th><th>Resolved</th><th>Hidden</th><th>Alert emails</th></tr></thead>
<tbody>
<?php foreach ($page->notifications as $notification): ?>
<tr><td><?= $e($utc($notification->openedAt)) ?></td><td><?php if (isset($instanceNames[$notification->instanceId])): ?><a href="/admin/instances/<?= $notification->instanceId ?>"><?= $e($instanceNames[$notification->instanceId]) ?></a><?php else: ?>Instance <?= $notification->instanceId ?><?php endif; ?></td><td><?= $e(NotificationsApi::checkLabel($notification->checkName)) ?></td><td><?= $e($notification->subject) ?></td><td><?= $notification->resolvedAt === null ? 'Open' : $e($utc($notification->resolvedAt)) ?></td><td><?= $notification->hidden ? 'Hidden by you' : '' ?></td><td><?php foreach ($page->alerts[$notification->id] ?? [] as $i => $alert): ?><?= $i > 0 ? '<br>' : '' ?><?= $e($alert->label() . ': ' . $alert->outcome()) ?><?php if ($alert->lastFailure !== null): ?><?= $partial('info', ['text' => $alert->lastFailure]) ?><?php endif; ?><?php endforeach; ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<?php if ($page->hasNewer || $page->olderBefore !== null): ?>
<p><?php if ($page->hasNewer): ?><a href="/admin/logs<?= $page->newerBefore === null ? '' : '?before=' . $page->newerBefore ?>">Newer</a><?php endif; ?><?php if ($page->hasNewer && $page->olderBefore !== null): ?> · <?php endif; ?><?php if ($page->olderBefore !== null): ?><a href="/admin/logs?before=<?= $page->olderBefore ?>">Older</a><?php endif; ?></p>
<?php endif; ?>
<h2>Actions</h2>
<p>What the escalation ladder decided on every instance, newest first.</p>
<?php if ($actions === []): ?>
<p>No actions yet.</p>
<?php else: ?>
<table>
<thead><tr><th>Decided</th><th>Instance</th><th>Action</th></tr></thead>
<tbody>
<?php foreach ($actions as $action): ?>
<tr><td><?= $e($utc($action->decidedAt)) ?></td><td><?php if (isset($instanceNames[$action->instanceId])): ?><a href="/admin/instances/<?= $action->instanceId ?>"><?= $e($instanceNames[$action->instanceId]) ?></a><?php else: ?>Instance <?= $action->instanceId ?><?php endif; ?></td><td><?= $e($action->sentence()) ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<?php if ($actionsBefore !== null || count($actions) === RemediationApi::ACTIONS_PER_LOG_PAGE): ?>
<p><?php if ($actionsBefore !== null): ?><a href="/admin/logs">Newest actions</a><?php endif; ?><?php if ($actionsBefore !== null && count($actions) === RemediationApi::ACTIONS_PER_LOG_PAGE): ?> · <?php endif; ?><?php if (count($actions) === RemediationApi::ACTIONS_PER_LOG_PAGE): ?><a href="/admin/logs?actions_before=<?= (int) $actions[count($actions) - 1]->id ?>">Older actions</a><?php endif; ?></p>
<?php endif; ?>
