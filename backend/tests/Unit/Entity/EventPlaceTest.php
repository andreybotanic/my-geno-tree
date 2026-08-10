<?php

namespace App\Tests\Unit\Entity;

use App\Entity\EventPlace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventPlaceTest extends TestCase
{
    public function testResolvedAndImportedPlacesPreserveTheCoordinatePair(): void
    {
        $resolved = EventPlace::resolved('Novosibirsk', 55.0302, 82.9204);
        self::assertSame('Novosibirsk', $resolved->getName());
        self::assertSame(55.0302, $resolved->getLatitude());
        self::assertSame(82.9204, $resolved->getLongitude());
        self::assertTrue($resolved->isResolved());

        $unresolved = EventPlace::imported('Text from GEDCOM', null, null);
        self::assertNull($unresolved->getLatitude());
        self::assertNull($unresolved->getLongitude());
        self::assertFalse($unresolved->isResolved());

        $unresolved->resolve('Resolved later', -33.8688, 151.2093);
        self::assertSame('Resolved later', $unresolved->getName());
        self::assertSame(-33.8688, $unresolved->getLatitude());
        self::assertSame(151.2093, $unresolved->getLongitude());
    }

    /** @return iterable<string, array{float|null, float|null}> */
    public static function invalidCoordinates(): iterable
    {
        yield 'latitude only' => [55.0, null];
        yield 'longitude only' => [null, 82.0];
        yield 'latitude below WGS84' => [-90.0000001, 0.0];
        yield 'latitude above WGS84' => [90.0000001, 0.0];
        yield 'longitude below WGS84' => [0.0, -180.0000001];
        yield 'longitude above WGS84' => [0.0, 180.0000001];
    }

    #[DataProvider('invalidCoordinates')]
    public function testRejectsIncompleteOrOutOfRangeCoordinates(
        ?float $latitude,
        ?float $longitude,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        EventPlace::imported('Invalid', $latitude, $longitude);
    }
}
