<?php

namespace App\Api;

use App\Entity\EventPlace;

final readonly class EventPlaceViewFactory
{
    /** @return array{name: string, coordinates: array{latitude: float, longitude: float}|null} */
    public function create(EventPlace $place): array
    {
        $coordinates = null;
        $latitude = $place->getLatitude();
        $longitude = $place->getLongitude();
        if (null !== $latitude && null !== $longitude) {
            $coordinates = [
                'latitude'  => $latitude,
                'longitude' => $longitude,
            ];
        }

        return [
            'name'        => $place->getName(),
            'coordinates' => $coordinates,
        ];
    }
}
