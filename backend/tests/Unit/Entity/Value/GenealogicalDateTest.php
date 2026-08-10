<?php

namespace App\Tests\Unit\Entity\Value;

use App\Entity\Value\DateKind;
use App\Entity\Value\GenealogicalDate;
use PHPUnit\Framework\TestCase;

final class GenealogicalDateTest extends TestCase
{
    public function testDateKindsExposeCorrectChronologyBounds(): void
    {
        self::assertSame('1994-01-01', GenealogicalDate::known(DateKind::EXACT, 1994, null, null)->earliest()?->format('Y-m-d'));
        self::assertSame('1994-12-31', GenealogicalDate::known(DateKind::EXACT, 1994, null, null)->latest()?->format('Y-m-d'));
        self::assertNull(GenealogicalDate::known(DateKind::APPROXIMATE, 1994, 10, 25)->earliest());
        self::assertNull(GenealogicalDate::known(DateKind::APPROXIMATE, 1994, 10, 25)->latest());
        self::assertSame('1930-07-22', GenealogicalDate::known(DateKind::BEFORE, 1930, 7, 23)->latest()?->format('Y-m-d'));
        self::assertNull(GenealogicalDate::known(DateKind::BEFORE, 1930, 7, 23)->earliest());
        self::assertSame('1967-01-01', GenealogicalDate::known(DateKind::AFTER, 1966, null, null)->earliest()?->format('Y-m-d'));
        self::assertNull(GenealogicalDate::known(DateKind::AFTER, 1966, null, null)->latest());
        $between = GenealogicalDate::known(DateKind::BETWEEN, 1899, null, null, 1905);
        self::assertSame('1899-01-01', $between->earliest()?->format('Y-m-d'));
        self::assertSame('1905-12-31', $between->latest()?->format('Y-m-d'));
    }
}
