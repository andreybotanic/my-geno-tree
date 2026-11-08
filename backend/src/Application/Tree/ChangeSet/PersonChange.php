<?php

namespace App\Application\Tree\ChangeSet;

use App\Application\Event\EventPlaceData;
use App\Application\Person\PersonData;
use App\Entity\Person;

final readonly class PersonChange
{
    /** @param array<'birthPlace'|'deathPlace', EventPlaceData|null> $places */
    public function __construct(
        public string $reference,
        public ?string $existingPersonId,
        public PersonData $data,
        public array $places = [],
    ) {
    }

    public function changes(Person $person): bool
    {
        foreach ($this->places as $field => $data) {
            $place = 'birthPlace' === $field ? $person->getBirthPlace() : $person->getDeathPlace();
            if ($data?->name !== $place?->getName()
                || $data?->latitude !== $place?->getLatitude()
                || $data?->longitude !== $place?->getLongitude()) {
                return true;
            }
        }

        return $this->data->birthSurname != $person->getBirthSurname()
            || $this->data->givenName != $person->getGivenName()
            || $this->data->patronymic != $person->getPatronymic()
            || $this->data->gender !== $person->getGender()
            || $this->data->birthDate != $person->getBirthDate()
            || $this->data->deathDate != $person->getDeathDate();
    }
}
