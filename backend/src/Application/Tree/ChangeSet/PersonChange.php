<?php

namespace App\Application\Tree\ChangeSet;

use App\Application\Person\PersonData;
use App\Entity\Person;

final readonly class PersonChange
{
    public function __construct(
        public string $reference,
        public ?string $existingPersonId,
        public PersonData $data,
    ) {
    }

    public function changes(Person $person): bool
    {
        return $this->data->birthSurname != $person->getBirthSurname()
            || $this->data->givenName != $person->getGivenName()
            || $this->data->patronymic != $person->getPatronymic()
            || $this->data->gender !== $person->getGender()
            || $this->data->birthDate != $person->getBirthDate()
            || $this->data->deathDate != $person->getDeathDate();
    }
}
