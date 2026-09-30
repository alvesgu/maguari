<?php

declare(strict_types=1);

namespace Maguari\Server\Fleet\Exception;

use Maguari\Server\Fleet\ProjectAccessProblem;

/**
 * Maguari cannot list instances in the project. The message is meant for the
 * administrator: the problem's sentence, plus a hint when credentials failed.
 */
final class ProjectNotAccessible extends \RuntimeException
{
    public function __construct(
        public readonly ProjectAccessProblem $problem,
        ?string $detail = null,
    ) {
        parent::__construct($problem->message() . ($detail === null ? '' : ' ' . $detail));
    }
}
