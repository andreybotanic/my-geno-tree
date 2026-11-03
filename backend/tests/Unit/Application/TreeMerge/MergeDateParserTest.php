<?php

namespace App\Tests\Unit\Application\TreeMerge;

use App\Api\ApiException;
use App\Application\TreeMerge\Ai\MergeDateParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MergeDateParserTest extends TestCase
{
    /** @param array<string, mixed> $expected */
    #[DataProvider('dates')]
    public function testPreservesDatePrecision(string $source, array $expected): void
    {
        self::assertSame($expected, (new MergeDateParser())->parse($source));
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function dates(): iterable
    {
        yield 'unknown' => ['unknown', ['state' => 'unknown']];
        yield 'year' => ['1938', ['state' => 'known', 'kind' => 'exact', 'year' => 1938]];
        yield 'approximate' => ['~1938', ['state' => 'known', 'kind' => 'approximate', 'year' => 1938]];
        yield 'before' => ['<1938-02', ['state' => 'known', 'kind' => 'before', 'year' => 1938, 'month' => 2]];
        yield 'after' => ['>1938-02-03', ['state' => 'known', 'kind' => 'after', 'year' => 1938, 'month' => 2, 'day' => 3]];
        yield 'range' => ['1938..1940', ['state' => 'known', 'kind' => 'between', 'year' => 1938, 'end' => ['year' => 1940]]];
        yield 'precise range' => ['1938-02-03..1940-04-05', ['state' => 'known', 'kind' => 'between', 'year' => 1938, 'month' => 2, 'day' => 3, 'end' => ['year' => 1940, 'month' => 4, 'day' => 5]]];
    }

    #[DataProvider('invalidDates')]
    public function testRejectsAmbiguousOrCombinedNotations(string $source): void
    {
        $this->expectException(ApiException::class);
        (new MergeDateParser())->parse($source);
    }

    /** @return iterable<array{string}> */
    public static function invalidDates(): iterable
    {
        foreach (['', '1938-ish', '~1938..1940', '38', '1938-1', '03/04/1938', "1938\n"] as $source) {
            yield [$source];
        }
    }
}
