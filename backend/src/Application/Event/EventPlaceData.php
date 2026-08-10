<?php

namespace App\Application\Event;

final readonly class EventPlaceData
{
    public function __construct(
        public string $name,
        public float $latitude,
        public float $longitude,
    ) {
    }
}
