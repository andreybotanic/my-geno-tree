<?php

namespace App\Application\TreeExchange;

final readonly class GedcomPlaceRecognition
{
    /**
     * @param array<string, array{name: string, latitude: float, longitude: float}> $places
     */
    public function __construct(
        public array $places,
        public int $unrecognizedPlaceCount,
    ) {
    }
}
