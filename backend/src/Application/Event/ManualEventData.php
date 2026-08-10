<?php

namespace App\Application\Event;

use App\Entity\Value\EventType;
use App\Entity\Value\GenealogicalDate;

final readonly class ManualEventData
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public EventType $type,
        public GenealogicalDate $date,
        public ?string $title,
        public ?string $description,
        public array $data,
        public ?EventPlaceData $place,
        public bool $placeProvided,
    ) {
    }
}
