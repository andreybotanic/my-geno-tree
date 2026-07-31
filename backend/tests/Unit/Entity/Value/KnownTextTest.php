<?php

namespace App\Tests\Unit\Entity\Value;

use App\Entity\Value\KnownText;
use PHPUnit\Framework\TestCase;

final class KnownTextTest extends TestCase
{
    public function testKnownAndUnknownValuesStayDistinct(): void
    {
        $known = KnownText::known('Анна');
        self::assertTrue($known->isKnown());
        self::assertSame('Анна', $known->getValue());

        $unknown = KnownText::unknown();
        self::assertFalse($unknown->isKnown());
        self::assertNull($unknown->getValue());
    }
}
