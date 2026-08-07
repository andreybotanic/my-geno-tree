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

        return $this->order($events);
    }

    /**
     * @param list<GenealogicalEvent> $events
     *
     * @return list<GenealogicalEvent>
     */
    private function order(array $events): array
    {
        $count = count($events);
        $following = array_fill(0, $count, []);
        $incoming = array_fill(0, $count, 0);
        foreach ($events as $leftIndex => $left) {
            foreach ($events as $rightIndex => $right) {
                if ($leftIndex === $rightIndex || 0 >= $this->chronology->compare(
                    $left->getDate(),
                    $right->getDate(),
                )) {
                    continue;
                }
                $following[$rightIndex][] = $leftIndex;
                ++$incoming[$leftIndex];
            }
        }

        $manualOrder = $this->completeManualOrder($events);
        $ordered = [];
        while (count($ordered) < $count) {
            $candidates = [];
            foreach ($events as $index => $event) {
                if (0 === $incoming[$index]) {
                    $candidates[] = $index;
                }
            }
            usort($candidates, function (int $left, int $right) use (
                $events,
                $manualOrder,
            ): int {
                if (null !== $manualOrder) {
                    return $manualOrder[$left] <=> $manualOrder[$right];
                }

                return $this->defaultCompare($events[$left], $events[$right]);
            });
            $index = $candidates[0] ?? throw new \LogicException('Event chronology must not contain a cycle.');
            $ordered[] = $events[$index];
            $incoming[$index] = -1;
            foreach ($following[$index] as $followingIndex) {
                --$incoming[$followingIndex];
            }
        }

        return $ordered;
    }

    /**
     * @param list<GenealogicalEvent> $events
     *
     * @return list<int>|null
     */
    private function completeManualOrder(array $events): ?array
    {
        $manualOrder = array_map(
            static fn (GenealogicalEvent $event): ?int => $event->getManualOrder(),
            $events,
        );
        if (in_array(null, $manualOrder, true)) {
            return null;
        }
        $sorted = $manualOrder;
        sort($sorted);
        if (array_values(range(0, count($events) - 1)) !== $sorted) {
            return null;
        }

        /* @var list<int> $manualOrder */
        return $manualOrder;
    }

    private function defaultCompare(
        GenealogicalEvent $left,
        GenealogicalEvent $right,
    ): int {
        $leftYear = $left->getDate()->getYear();
        $rightYear = $right->getDate()->getYear();
        if (null === $leftYear || null === $rightYear) {
            return (null === $leftYear) <=> (null === $rightYear)
                ?: $left->getCreatedAt() <=> $right->getCreatedAt()
                ?: $left->getId()->toRfc4122() <=> $right->getId()->toRfc4122();
        }

        $year = $leftYear <=> $rightYear;
        if (0 !== $year) {
            return $year;
        }
        $leftEarliest = $left->getDate()->earliest();
        $rightEarliest = $right->getDate()->earliest();
        if (null !== $leftEarliest && null !== $rightEarliest) {
            $chronological = $leftEarliest <=> $rightEarliest;
            if (0 !== $chronological) {
                return $chronological;
            }
        }

        return $left->getCreatedAt() <=> $right->getCreatedAt()
            ?: $left->getId()->toRfc4122() <=> $right->getId()->toRfc4122();
    }
}
