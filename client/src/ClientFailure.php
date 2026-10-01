<?php

declare(strict_types=1);

namespace Maguari\Client;

/**
 * Anything that stops a command, with a one-line message for the person
 * reading the terminal or the journal. Messages never contain secrets.
 */
final class ClientFailure extends \RuntimeException
{
}
