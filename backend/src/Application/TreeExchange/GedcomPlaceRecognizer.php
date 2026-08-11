<?php

namespace App\Application\TreeExchange;

use App\Application\Map\GeoapifyGeocoder;

final readonly class GedcomPlaceRecognizer
{
    public function __construct(private GeoapifyGeocoder $geocoder)
    {
    }

    public function recognize(GedcomImportDocument $document, string $language): GedcomPlaceRecognition
    {
        $names = $document->unresolvedPlaceNames();
        $matches = $this->geocoder->batchBestMatches($names, $language);
        $recognized = [];
        foreach ($matches as $name => $place) {
            $recognized[$name] = [
                'name'      => $place['name'],
                'latitude'  => $place['coordinates']['latitude'],
                'longitude' => $place['coordinates']['longitude'],
            ];
        }

        return new GedcomPlaceRecognition($recognized, count($names) - count($recognized));
    }
}
