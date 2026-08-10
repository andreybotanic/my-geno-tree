<?php

namespace App\Api;

use App\Entity\MarriagePeriod;

final readonly class MarriagePeriodViewFactory
{
    public function __construct(
        private GenealogicalDateViewFactory $dates,
        private EventPlaceViewFactory $places,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(MarriagePeriod $marriage): array
    {
        $dissolutionDate = $marriage->getDissolutionDate();

        return [
            'id'             => $marriage->getId()->toRfc4122(),
            'malePersonId'   => $marriage->getMalePerson()->getId()->toRfc4122(),
            'femalePersonId' => $marriage
                ->getFemalePerson()
                ->getId()
                ->toRfc4122(),
            'marriageDate' => $this->dates->create(
                $marriage->getMarriageDate(),
            ),
            'dissolutionDate' => null === $dissolutionDate
                ? null
                : $this->dates->create($dissolutionDate),
            'marriagePlace' => null === $marriage->getMarriagePlace()
                ? null
                : $this->places->create($marriage->getMarriagePlace()),
            'dissolutionPlace' => null === $marriage->getDissolutionPlace()
                ? null
                : $this->places->create($marriage->getDissolutionPlace()),
            'createdAt' => $marriage->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $marriage->getUpdatedAt()->format(DATE_ATOM),
        ];
    }
}
