<?php declare(strict_types=1);
/**
 * The daily job's latest run in one line, shared by the dashboard and the
 * instance page (design section 6.3).
 *
 * @var \Closure $e @var ?\Maguari\Server\Monitoring\Domain\DailyJobRun $run
 */
use Maguari\Server\Monitoring\Domain\DailyJobState;

$utc = static fn (int $at): string => gmdate('Y-m-d H:i', $at) . ' UTC';
?>
<p><?php if ($run === null): ?>The daily job has never run.<?php
elseif ($run->state === DailyJobState::Running): ?>Running since <?= $e($utc($run->startedAt)) ?> (<?= $e($run->trigger->value) ?>).<?php
elseif ($run->state === DailyJobState::Killed): ?>The last run, started at <?= $e($utc($run->startedAt)) ?> (<?= $e($run->trigger->value) ?>), did not finish.<?php
elseif ($run->state === DailyJobState::Failed): ?>The last run, started at <?= $e($utc($run->startedAt)) ?> (<?= $e($run->trigger->value) ?>), failed. The details are in the server's error log.<?php
else: ?><?php $took = (int) $run->finishedAt - $run->startedAt; ?>Last run: <?= $e($utc($run->startedAt)) ?> (<?= $e($run->trigger->value) ?>), took <?= $took ?> <?= $took === 1 ? 'second' : 'seconds' ?>.<?php
endif; ?></p>
