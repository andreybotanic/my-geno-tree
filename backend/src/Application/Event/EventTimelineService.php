<?php

namespace App\Application\Event;

use App\Entity\GenealogicalEvent;
use App\Entity\Person;
use App\Repository\GenealogicalEventRepository;

final readonly class EventTimelineService
{
    public function __construct(
        private GenealogicalEventRepository $events,
        private EventChronology $chronology,
    ) {
    }

    /**
     * @return list<GenealogicalEvent>
     */
    public function forPerson(Person $person): array
    {
        $events = $this->events->findTimeline($person);
        usort($events, $this->compare(...));

        return $events;
    }

    private function compare(
        GenealogicalEvent $left,
        GenealogicalEvent $right,
    ): int {
        $chronological = $this->chronology->compare(
            $left->getDate(),
            $right->getDate(),
        );
        if (0 !== $chronological) {
            return $chronological;
        }

        return ($left->getManualOrder() ?? PHP_INT_MAX)
            <=> ($right->getManualOrder() ?? PHP_INT_MAX)
            ?: $left->getCreatedAt() <=> $right->getCreatedAt()
            ?: $left->getId()->toRfc4122() <=> $right->getId()->toRfc4122();
    }
}
