<?php

namespace App\Application\Event;

use App\Entity\GenealogicalEvent;
use App\Entity\Person;
use App\Repository\GenealogicalEventRepository;

final readonly class EventTimelineService
{
    public function __construct(
        private GenealogicalEventRepository $events,
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
        $leftEarliest = $left->getDate()->earliest();
        $rightEarliest = $right->getDate()->earliest();
        $leftLatest = $left->getDate()->latest();
        $rightLatest = $right->getDate()->latest();

        if (
            null !== $leftLatest
            && null !== $rightEarliest
            && $leftLatest < $rightEarliest
        ) {
            return -1;
        }
        if (
            null !== $rightLatest
            && null !== $leftEarliest
            && $rightLatest < $leftEarliest
        ) {
            return 1;
        }

        return ($left->getManualOrder() ?? PHP_INT_MAX)
            <=> ($right->getManualOrder() ?? PHP_INT_MAX)
            ?: $left->getCreatedAt() <=> $right->getCreatedAt()
            ?: $left->getId()->toRfc4122() <=> $right->getId()->toRfc4122();
    }
}
