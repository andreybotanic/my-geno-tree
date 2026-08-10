<?php

namespace App\Application\TreeExchange;

use App\Entity\EventPlace;

/** The only application boundary that may create a text-only event place. */
final readonly class GedcomEventPlaceFactory
{
    /** @param array{name: string, latitude: float|null, longitude: float|null}|null $data */
    public function create(?array $data): ?EventPlace
    {
        if (null === $data) {
            return null;
        }

        return EventPlace::imported(
            $data['name'],
            $data['latitude'],
            $data['longitude'],
        );
    }
}
