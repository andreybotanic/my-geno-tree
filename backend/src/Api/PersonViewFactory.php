<?php

namespace App\Api;

use App\Entity\Person;

final readonly class PersonViewFactory
{
    public function __construct(
        private KnownTextViewFactory $texts,
        private GenealogicalDateViewFactory $dates,
        private EventPlaceViewFactory $places,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(Person $person): array
    {
        return [
            'id'           => $person->getId()->toRfc4122(),
            'birthSurname' => $this->texts->create(
                $person->getBirthSurname(),
            ),
            'givenName'  => $this->texts->create($person->getGivenName()),
            'patronymic' => $this->texts->create($person->getPatronymic()),
            'gender'     => $person->getGender()->value,
            'birthDate'  => $this->dates->create($person->getBirthDate()),
            'deathDate'  => null === $person->getDeathDate()
                ? null
                : $this->dates->create($person->getDeathDate()),
            'birthPlace' => null === $person->getBirthPlace()
                ? null
                : $this->places->create($person->getBirthPlace()),
            'deathPlace' => null === $person->getDeathPlace()
                ? null
                : $this->places->create($person->getDeathPlace()),
            'photoThumbnailUrl' => null === $person->getPhotoAttachment()
                ? null
                : sprintf(
                    '/trees/%s/people/%s/photo/thumbnail?media=%s',
                    $person->getTree()->getId()->toRfc4122(),
                    $person->getId()->toRfc4122(),
                    $person->getPhotoAttachment()
                        ->getMedia()
                        ->getId()
                        ->toRfc4122(),
                ),
            'createdAt' => $person->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $person->getUpdatedAt()->format(DATE_ATOM),
        ];
    }
}
