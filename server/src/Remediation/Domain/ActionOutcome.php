<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Domain;

/**
 * How an action ended. Stored in remediation_actions.outcome. Only dry-run
 * so far; later steps of phase 3 add what a client or the API reports.
 */
enum ActionOutcome: string
{
    /** Dry run: the action was decided and nothing was done. */
    case WouldRun = 'would_run';
}
