<?php

namespace App\Api;

use App\Entity\GenealogicalEvent;

final readonly class EventViewFactory
{
    public function __construct(
        private GenealogicalDateViewFactory $dates,
        private EventPlaceViewFactory $places,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(GenealogicalEvent $event): array
    {
        $data = $event->getData();

        return [
            'id'       => $event->getId()->toRfc4122(),
            'personId' => $event->getPerson()->getId()->toRfc4122(),
            'type'     => $event->getType()->value,
            'source'   => $event->getSource()->value,
            'date'     => $this->dates->create($event->getDate()),
            'place'    => null === $event->getPlace()
                ? null
                : $this->places->create($event->getPlace()),
            'title'            => $event->getTitle(),
            'description'      => $event->getDescription(),
            'data'             => [] === $data ? new \stdClass() : $data,
            'sourceEntityType' => $event->getSourceEntityType(),
            'sourceEntityId'   => $event->getSourceEntityId()?->toRfc4122(),
            'manualOrder'      => $event->getManualOrder(),
            'createdAt'        => $event->getCreatedAt()->format(DATE_ATOM),
            'updatedAt'        => $event->getUpdatedAt()->format(DATE_ATOM),
        ];
    }
}
