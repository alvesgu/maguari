<?php declare(strict_types=1);
/**
 * One instance's enrollment and heartbeat cells, shared by the dashboard and
 * the project page.
 *
 * @var \Closure $e @var \Maguari\Server\Clients\EnrollmentState $enrollment @var ?\Maguari\Server\Clients\HeartbeatStatus $heartbeat
 */ ?>
<td><?= $e($enrollment->label()) ?></td>
<td><?= $heartbeat === null ? '' : $e($heartbeat->state->label()) ?></td>
<td><?php if ($heartbeat !== null && $heartbeat->lastHeartbeatAt !== null && $heartbeat->ageSeconds !== null): ?><?= $e(gmdate('Y-m-d H:i:s', $heartbeat->lastHeartbeatAt)) ?> UTC (<?= $e(\Maguari\Server\Http\TimeAgo::format($heartbeat->ageSeconds)) ?>)<?php endif; ?></td>
