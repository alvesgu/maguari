<?php

declare(strict_types=1);

namespace Maguari\Server\Http;

/**
 * Every value given for each query parameter, in order. PHP's own parsing
 * keeps only the last of a repeated name, which would hide a mistake.
 */
final class QueryParameters
{
    /**
     * @return array<string, list<string>>
     */
    public static function all(string $query): array
    {
        $parameters = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $parameters[urldecode($name)][] = urldecode($value);
        }

        return $parameters;
    }
}
