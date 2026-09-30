<?php

declare(strict_types=1);

namespace Maguari\Server\Access\Exception;

final class InvalidSetupInput extends \RuntimeException
{
    /**
     * @param array<string, string> $errors messages keyed by field name
     */
    public function __construct(
        public readonly array $errors,
    ) {
        parent::__construct('The setup form has errors.');
    }
}
