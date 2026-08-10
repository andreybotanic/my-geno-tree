<?php

namespace App\Application\TreeExchange;

use App\Api\ApiException;
use App\Application\Map\NominatimGeocoder;

final readonly class GedcomPlaceRecognizer
{
    public function __construct(private NominatimGeocoder $geocoder)
    {
    }

    /** @return array<string, array{name: string, latitude: float, longitude: float}> */
    public function recognize(GedcomImportDocument $document, string $language): array
    {
        $recognized = [];
        foreach ($document->unresolvedPlaceNames() as $name) {
            try {
                $place = $this->geocoder->bestMatch($name, $language);
            } catch (ApiException $exception) {
                if (in_array($exception->getErrorCode(), ['GEOCODING_UNAVAILABLE', 'RATE_LIMIT_EXCEEDED'], true)) {
                    return $recognized;
                }
                if ('GEOCODING_QUERY_INVALID' === $exception->getErrorCode()) {
                    continue;
                }

                throw $exception;
            }
            if (null === $place) {
                continue;
            }
            $recognized[$name] = [
                'name'      => $place['name'],
                'latitude'  => $place['coordinates']['latitude'],
                'longitude' => $place['coordinates']['longitude'],
            ];
        }

        return $recognized;
    }
}
