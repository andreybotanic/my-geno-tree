<?php

namespace App\Application\Event;

use App\Api\ApiException;
use App\Application\Tree\TreeVersion;
use App\Entity\EventPlace;
use App\Entity\FamilyTree;
use App\Entity\GenealogicalEvent;
use App\Entity\Person;
use App\Repository\GenealogicalEventRepository;
use App\Repository\PersonRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Uid\Uuid;

final readonly class ManualEventService
{
    public function __construct(
        private ManualEventDataPolicy $dataPolicy,
        private EventChronology $chronology,
        private EventTimelineService $timeline,
        private GenealogicalEventRepository $events,
        private PersonRepository $people,
        private TreeVersion $versions,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(
        FamilyTree $tree,
        Person $person,
        array $payload,
        ?string $ifMatch,
    ): GenealogicalEvent {
        return $this->createMany($tree, [$person], $payload, $ifMatch)[0];
    }

    /**
     * Creates independent event records for all participants atomically.
     *
     * @param list<Person>         $people
     * @param array<string, mixed> $payload
     *
     * @return list<GenealogicalEvent>
     */
    public function createMany(
        FamilyTree $tree,
        array $people,
        array $payload,
        ?string $ifMatch,
    ): array {
        if ([] === $people || !array_is_list($people)) {
            throw new ApiException(422, 'EVENT_PARTICIPANTS_INVALID');
        }
        $ids = [];
        foreach ($people as $person) {
            if (!$person->getTree()->getId()->equals($tree->getId())) {
                throw new ApiException(404, 'PERSON_NOT_FOUND');
            }
            $id = $person->getId()->toRfc4122();
            if (isset($ids[$id])) {
                throw new ApiException(422, 'EVENT_PARTICIPANTS_INVALID');
            }
            $ids[$id] = true;
        }

        return $this->entityManager->wrapInTransaction(
            function () use (
                $tree,
                $people,
                $ids,
                $payload,
                $ifMatch,
            ): array {
                $this->entityManager->lock($tree, LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->refresh($tree);
                $this->versions->assertMatches($tree, $ifMatch);
                // Every mutation locks the tree, so one refresh query replaces per-person locks and refreshes.
                $managed = $this->people->findRelationshipPeopleByTreeAndIds($tree, array_keys($ids), true);
                if (count($managed) !== count($people)) {
                    throw new ApiException(404, 'PERSON_NOT_FOUND');
                }
                $byId = [];
                foreach ($managed as $person) {
                    $byId[$person->getId()->toRfc4122()] = $person;
                }
                $people = array_map(
                    static fn (Person $person): Person => $byId[$person->getId()->toRfc4122()],
                    $people,
                );
                $validated = array_map(
                    fn (Person $person): ManualEventData => $this->dataPolicy->event($person, $payload),
                    $people,
                );
                $changedAt = $this->clock->now();
                $created = [];
                foreach ($people as $index => $person) {
                    $data = $validated[$index];
                    $place = null;
                    if (null !== $data->place) {
                        $place = EventPlace::resolved(
                            $data->place->name,
                            $data->place->latitude,
                            $data->place->longitude,
                        );
                        $this->entityManager->persist($place);
                    }
                    $event = GenealogicalEvent::manual(
                        $person,
                        $data->type,
                        $data->date,
                        $data->title,
                        $data->description,
                        $data->data,
                        $changedAt,
                        $place,
                    );
                    $this->entityManager->persist($event);
                    $created[] = $event;
                }
                $tree->touch($changedAt);

                return $created;
            },
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(
        FamilyTree $tree,
        Person $person,
        string $eventId,
        array $payload,
        ?string $ifMatch,
    ): GenealogicalEvent {
        $event = $this->manualEvent($person, $eventId);

        return $this->entityManager->wrapInTransaction(function () use (
            $tree,
            $person,
            $event,
            $payload,
            $ifMatch,
        ): GenealogicalEvent {
            $this->lock($tree, $person, $ifMatch);
            $this->entityManager->lock($event, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($event);
            $data = $this->dataPolicy->event($person, $payload);
            $changedAt = $this->clock->now();
            $event->changeManual(
                $data->type,
                $data->date,
                $data->title,
                $data->description,
                $data->data,
                $changedAt,
            );
            if ($data->placeProvided) {
                $place = $event->getPlace();
                if (null === $data->place) {
                    $event->changePlace(null, $changedAt);
                    if (null !== $place) {
                        $this->entityManager->remove($place);
                    }
                } elseif (null === $place) {
                    $place = EventPlace::resolved(
                        $data->place->name,
                        $data->place->latitude,
                        $data->place->longitude,
                    );
                    $this->entityManager->persist($place);
                    $event->changePlace($place, $changedAt);
                } else {
                    $place->resolve(
                        $data->place->name,
                        $data->place->latitude,
                        $data->place->longitude,
                    );
                }
            }
            if (
                null !== $event->getManualOrder()
                && !$this->hasAmbiguousPeer($person, $event)
            ) {
                $event->changeManualOrder(null, $changedAt);
            }
            $tree->touch($changedAt);

            return $event;
        });
    }

    public function delete(
        FamilyTree $tree,
        Person $person,
        string $eventId,
        ?string $ifMatch,
    ): void {
        $event = $this->manualEvent($person, $eventId);
        $this->entityManager->wrapInTransaction(function () use (
            $tree,
            $person,
            $event,
            $ifMatch,
        ): void {
            $this->lock($tree, $person, $ifMatch);
            $this->entityManager->lock($event, LockMode::PESSIMISTIC_WRITE);
            $changedAt = $this->clock->now();
            if (null !== $event->getPlace()) {
                $this->entityManager->remove($event->getPlace());
            }
            $this->entityManager->remove($event);
            $tree->touch($changedAt);
        });
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<GenealogicalEvent>
     */
    public function reorder(
        FamilyTree $tree,
        Person $person,
        array $payload,
        ?string $ifMatch,
    ): array {
        $eventIds = $this->dataPolicy->eventOrder($payload);

        return $this->entityManager->wrapInTransaction(function () use (
            $tree,
            $person,
            $eventIds,
            $ifMatch,
        ): array {
            $this->lock($tree, $person, $ifMatch);
            $timeline = $this->timeline->forPerson($person);
            $eventsById = [];
            foreach ($timeline as $event) {
                $eventsById[$event->getId()->toRfc4122()] = $event;
            }
            $ordered = [];
            foreach ($eventIds as $eventId) {
                if (!isset($eventsById[$eventId])) {
                    $this->notFound();
                }
                $ordered[] = $eventsById[$eventId];
            }
            if (count($ordered) !== count($timeline)) {
                $this->invalidOrder();
            }
            $changed = array_keys(array_filter(
                $timeline,
                static fn (GenealogicalEvent $event, int $index): bool => $event !== $ordered[$index],
                ARRAY_FILTER_USE_BOTH,
            ));
            if (
                2 !== count($changed)
                || 1 !== $changed[1] - $changed[0]
                || $timeline[$changed[0]] !== $ordered[$changed[1]]
                || $timeline[$changed[1]] !== $ordered[$changed[0]]
                || !$this->chronology->isAmbiguous(
                    $timeline[$changed[0]]->getDate(),
                    $timeline[$changed[1]]->getDate(),
                )
            ) {
                $this->invalidOrder();
            }

            $changedAt = $this->clock->now();
            foreach ($ordered as $index => $event) {
                $this->entityManager->lock(
                    $event,
                    LockMode::PESSIMISTIC_WRITE,
                );
                $event->changeManualOrder($index, $changedAt);
            }
            $tree->touch($changedAt);

            return $this->timeline->forPerson($person);
        });
    }

    private function lock(
        FamilyTree $tree,
        Person $person,
        ?string $ifMatch,
    ): void {
        $this->entityManager->lock($tree, LockMode::PESSIMISTIC_WRITE);
        $this->entityManager->refresh($tree);
        $this->versions->assertMatches($tree, $ifMatch);
        $this->entityManager->lock($person, LockMode::PESSIMISTIC_WRITE);
        $this->entityManager->refresh($person);
    }

    private function manualEvent(
        Person $person,
        string $eventId,
    ): GenealogicalEvent {
        $event = Uuid::isValid($eventId)
            ? $this->events->findManualForPerson($person, $eventId)
            : null;
        if (null === $event) {
            $this->notFound();
        }

        return $event;
    }

    private function hasAmbiguousPeer(
        Person $person,
        GenealogicalEvent $changed,
    ): bool {
        foreach ($this->events->findTimeline($person) as $event) {
            if (
                $event !== $changed
                && $this->chronology->isAmbiguous(
                    $changed->getDate(),
                    $event->getDate(),
                )
            ) {
                return true;
            }
        }

        return false;
    }

    private function invalidOrder(): never
    {
        throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'EVENT_ORDER_INVALID', 'event_order_invalid', ['eventIds' => ['events_not_ambiguous']]);
    }

    private function notFound(): never
    {
        throw new ApiException(JsonResponse::HTTP_NOT_FOUND, 'NOT_FOUND', 'not_found');
    }
}
