<?php

declare(strict_types=1);

namespace Maguari\Server\Http\Controller;

final class FormInput
{
    /**
     * A string field from query or form data, or '' when it is missing or not a
     * string (for example name[]=x).
     *
     * @param array<mixed> $input
     */
    public static function string(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
