<?php

namespace App\Application\Person;

final class PersonNameNormalizer
{
    public function normalize(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = strtr($value, ['ё' => 'е']);
        $replaced = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
        if (!is_string($replaced)) {
            return '';
        }
        $value = $replaced;

        $collapsed = preg_replace('/\s+/u', ' ', $value);

        return is_string($collapsed) ? trim($collapsed) : '';
    }

    public function maximumDistance(string $normalizedQuery): int
    {
        return min(3, max(1, intdiv(mb_strlen($normalizedQuery, 'UTF-8'), 6)));
    }
}
