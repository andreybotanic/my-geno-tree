<?php

namespace App\Application\Person;

use App\Application\Event\EventPlaceData;
use App\Entity\Value\Gender;
use App\Entity\Value\GenealogicalDate;
use App\Entity\Value\KnownText;

final readonly class PersonData
{
    public function __construct(
        public KnownText $birthSurname,
        public KnownText $givenName,
        public KnownText $patronymic,
        public Gender $gender,
        public GenealogicalDate $birthDate,
        public ?GenealogicalDate $deathDate,
        public ?EventPlaceData $birthPlace = null,
        public ?EventPlaceData $deathPlace = null,
    ) {
    }
}
