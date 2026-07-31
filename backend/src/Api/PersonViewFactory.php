<?php

namespace App\Api;

use App\Entity\Person;

final readonly class PersonViewFactory
{
    public function __construct(
        private KnownTextViewFactory $texts,
        private GenealogicalDateViewFactory $dates,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(Person $person): array
    {
        return [
            'id' => $person->getId()->toRfc4122(),
            'birthSurname' => $this->texts->create(
                $person->getBirthSurname(),
            ),
            'givenName' => $this->texts->create($person->getGivenName()),
            'patronymic' => $this->texts->create($person->getPatronymic()),
            'gender' => $person->getGender()->value,
            'birthDate' => $this->dates->create($person->getBirthDate()),
            'deathDate' => null === $person->getDeathDate()
                ? null
                : $this->dates->create($person->getDeathDate()),
            'photoThumbnailUrl' => null,
            'coordinates' => [
                'x' => $person->getX(),
                'y' => $person->getY(),
            ],
            'createdAt' => $person->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $person->getUpdatedAt()->format(DATE_ATOM),
        ];
    }
}
