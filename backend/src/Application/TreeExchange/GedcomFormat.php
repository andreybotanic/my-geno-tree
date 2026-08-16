<?php

namespace App\Application\TreeExchange;

enum GedcomFormat: string
{
    case V551 = 'gedcom-5.5.1';
    case V7 = 'gedcom-7';

    public function inspectionName(): string
    {
        return match ($this) {
            self::V551 => 'GEDCOM 5.5.1',
            self::V7   => 'GEDCOM 7.0',
        };
    }

    public static function fromVersion(string $version): ?self
    {
        return match (true) {
            in_array($version, ['5.5', '5.5.1'], true)       => self::V551,
            1 === preg_match('/^7\.0(?:\.\d+)?$/', $version) => self::V7,
            default                                          => null,
        };
    }
}
