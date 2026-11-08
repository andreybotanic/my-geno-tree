<?php

namespace App\Application\Tree\ChangeSet;

use App\Application\Event\EventPlaceData;
use App\Entity\Value\GenealogicalDate;

final readonly class MarriageChange
{
    /** @param array<'marriagePlace'|'dissolutionPlace', EventPlaceData|null> $places */
    public function __construct(
        public string $first,
        public string $second,
        public GenealogicalDate $marriageDate,
        public ?GenealogicalDate $dissolutionDate,
        public bool $newPeriod = false,
        public array $places = [],
        public ?string $maleNewSurname = null,
        public ?string $femaleNewSurname = null,
    ) {
    }
}
