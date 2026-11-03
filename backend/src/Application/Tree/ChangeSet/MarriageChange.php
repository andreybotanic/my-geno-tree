<?php

namespace App\Application\Tree\ChangeSet;

use App\Entity\Value\GenealogicalDate;

final readonly class MarriageChange
{
    public function __construct(
        public string $first,
        public string $second,
        public GenealogicalDate $marriageDate,
        public ?GenealogicalDate $dissolutionDate,
        public bool $newPeriod = false,
    ) {
    }
}
