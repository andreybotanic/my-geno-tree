<?php

namespace App\Tests\Unit\Entity\Value;

use App\Entity\Value\DatePrecision;
use App\Entity\Value\GenealogicalDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GenealogicalDateTest extends TestCase
{
    /**
     * @return iterable<string, array{
     *     DatePrecision,
     *     int,
     *     int|null,
     *     int|null,
     *     string,
     *     string
     * }>
     */
    public static function knownDateRanges(): iterable
    {
        yield 'year' => [
            DatePrecision::YEAR,
            1994,
            null,
            null,
            '1994-01-01',
            '1994-12-31',
        ];
        yield 'leap month' => [
            DatePrecision::MONTH,
            2000,
            2,
            null,
            '2000-02-01',
            '2000-02-29',
        ];
        yield 'day' => [
            DatePrecision::DAY,
            1994,
            10,
            25,
            '1994-10-25',
            '1994-10-25',
        ];
    }

    #[DataProvider('knownDateRanges')]
    public function testKnownDateProducesItsPossibleRange(
        DatePrecision $precision,
        int $year,
        ?int $month,
        ?int $day,
        string $earliest,
        string $latest,
    ): void {
        $date = GenealogicalDate::known(
            $precision,
            $year,
            $month,
            $day,
            false,
        );

        self::assertTrue($date->isKnown());
        self::assertSame($precision, $date->getPrecision());
        self::assertSame($year, $date->getYear());
        self::assertSame($month, $date->getMonth());
        self::assertSame($day, $date->getDay());
        self::assertFalse($date->isApproximate());
        self::assertSame($earliest, $date->earliest()?->format('Y-m-d'));
        self::assertSame($latest, $date->latest()?->format('Y-m-d'));
    }

    public function testUnknownAndApproximateDatesCannotProveChronology(): void
    {
        $unknown = GenealogicalDate::unknown();
        self::assertFalse($unknown->isKnown());
        self::assertNull($unknown->getPrecision());
        self::assertNull($unknown->earliest());
        self::assertNull($unknown->latest());

        $approximate = GenealogicalDate::known(
            DatePrecision::YEAR,
            1900,
            null,
            null,
            true,
        );
        self::assertTrue($approximate->isApproximate());
        self::assertNull($approximate->earliest());
        self::assertNull($approximate->latest());
    }
}
