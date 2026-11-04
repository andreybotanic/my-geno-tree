<?php

namespace App\Application\TreeMerge\Ai;

use App\Api\ApiException;

/** Converts the compact AI transport notation; PersonDataPolicy validates the resulting domain date. */
final class MergeDateParser
{
    /** @return array<string, mixed> */
    public function parse(string $value, string $path = 'date'): array
    {
        if ('unknown' === $value) {
            return ['state' => 'unknown'];
        }
        if (!preg_match('/^([~<>]?)(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?(?:\.\.(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?)?$/D', $value, $parts)
            || (isset($parts[5]) && '' !== $parts[1])) {
            throw new ApiException(422, 'TREE_MERGE_DATE_INVALID', fields: [$path => ['date_notation_invalid']], details: ['violations' => [['path' => $path, 'code' => 'date_notation_invalid', 'message' => 'Use unknown, YYYY, YYYY-MM, YYYY-MM-DD, ~DATE, <DATE, >DATE or DATE..DATE. Preserve the source precision; never guess missing date parts.']]]);
        }
        $date = ['state' => 'known', 'kind' => isset($parts[5]) ? 'between' : match ($parts[1]) {
            '~' => 'approximate', '<' => 'before', '>' => 'after', default => 'exact',
        }, 'year' => (int)$parts[2]];
        foreach ([3 => 'month', 4 => 'day'] as $index => $key) {
            if (isset($parts[$index]) && '' !== $parts[$index]) {
                $date[$key] = (int)$parts[$index];
            }
        }
        if (isset($parts[5])) {
            $date['end'] = ['year' => (int)$parts[5]];
            foreach ([6 => 'month', 7 => 'day'] as $index => $key) {
                if (isset($parts[$index]) && '' !== $parts[$index]) {
                    $date['end'][$key] = (int)$parts[$index];
                }
            }
        }

        return $date;
    }
}
