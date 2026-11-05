<?php

declare(strict_types=1);

namespace Maguari\Server\Remediation\Domain;

/**
 * What an action does (glossary). Stored in remediation_actions.action.
 */
enum ActionKind: string
{
    case Restart = 'restart';
    case Reboot = 'reboot';
    case Reset = 'reset';

    /**
     * Whether it takes the whole instance down, so the boot grace period
     * follows it.
     */
    public function isInstanceWide(): bool
    {
        return $this !== self::Restart;
    }
}
