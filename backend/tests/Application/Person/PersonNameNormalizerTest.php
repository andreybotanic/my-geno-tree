<?php

namespace App\Tests\Application\Person;

use App\Application\Person\PersonNameNormalizer;
use PHPUnit\Framework\TestCase;

final class PersonNameNormalizerTest extends TestCase
{
    public function testItNormalizesUnicodeCaseYoAndSeparators(): void
    {
        $normalizer = new PersonNameNormalizer();

        self::assertSame(
            'елкин егор сергеевич',
            $normalizer->normalize(" ЁЛКИН—Егор\tСергеевич "),
        );
    }

    public function testItBoundsTheLevenshteinDistanceToTheQueryLength(): void
    {
        $normalizer = new PersonNameNormalizer();

        self::assertSame(1, $normalizer->maximumDistance('ив'));
        self::assertSame(2, $normalizer->maximumDistance('ивановичпетр'));
        self::assertSame(3, $normalizer->maximumDistance(str_repeat('а', 24)));
    }
}
