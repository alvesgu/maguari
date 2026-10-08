<?php

declare(strict_types=1);

namespace Maguari\Server\Notifications\Exception;

/**
 * No SMTP settings are stored. The message is a fixed sentence for the
 * administrator.
 */
final class EmailNotSetUp extends \RuntimeException
{
}
