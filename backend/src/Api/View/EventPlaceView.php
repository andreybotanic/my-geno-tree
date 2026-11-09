<?php

namespace App\Api\View;

use App\Api\Mapping\MapObject;
use App\Entity\EventPlace;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: EventPlace::class)]
final readonly class EventPlaceView
{
    public function __construct(
        public string $name,
        #[Map(source: 'latitude', transform: [[self::class, 'coordinates'], new MapObject(CoordinatesView::class)])]
        public ?CoordinatesView $coordinates,
    ) {
    }

    public static function coordinates(mixed $latitude, object $place): ?EventPlace
    {
        assert($place instanceof EventPlace);

        return null !== $latitude && null !== $place->getLongitude() ? $place : null;
    }
}
