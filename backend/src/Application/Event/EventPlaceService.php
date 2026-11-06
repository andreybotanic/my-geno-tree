<?php

namespace App\Application\Event;

use App\Api\ApiException;
use App\Application\Tree\TreeVersion;
use App\Entity\EventPlace;
use App\Entity\FamilyTree;
use App\Entity\GenealogicalEvent;
use App\Entity\MarriagePeriod;
use App\Entity\Person;
use App\Entity\Value\EventSource;
use App\Entity\Value\EventType;
use App\Repository\GenealogicalEventRepository;
use App\Repository\MarriagePeriodRepository;
use App\Repository\ParentLinkRepository;
use App\Repository\PersonRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Uid\Uuid;

final readonly class EventPlaceService
{
    public function __construct(
        private EventPlaceDataPolicy $placeData,
        private GenealogicalEventRepository $events,
        private PersonRepository $people,
        private ParentLinkRepository $parentLinks,
        private MarriagePeriodRepository $marriages,
        private TreeVersion $versions,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function put(
        FamilyTree $tree,
        string $eventId,
        array $payload,
        ?string $ifMatch,
    ): GenealogicalEvent {
        $data = $this->placeData->resolved($payload);

        return $this->entityManager->wrapInTransaction(
            function () use ($tree, $eventId, $data, $ifMatch): GenealogicalEvent {
                $event = $this->lockEvent($tree, $eventId, $ifMatch);
                [$group, $person, $marriage] = $this->group($tree, $event);
                $places = $this->places($group, $person, $marriage, $event->getType());
                $place = $places[0] ?? EventPlace::resolved(
                    $data->name,
                    $data->latitude,
                    $data->longitude,
                );
                if ([] === $places) {
                    $this->entityManager->persist($place);
                } else {
                    $place->resolve($data->name, $data->latitude, $data->longitude);
                }
                $changedAt = $this->clock->now();
                $this->assign($group, $person, $marriage, $event->getType(), $place, $changedAt);
                foreach (array_slice($places, 1) as $stalePlace) {
                    $this->entityManager->remove($stalePlace);
                }
                $tree->touch($changedAt);

                return $event;
            },
        );
    }

    public function delete(
        FamilyTree $tree,
        string $eventId,
        ?string $ifMatch,
    ): void {
        $this->entityManager->wrapInTransaction(function () use ($tree, $eventId, $ifMatch): void {
            $event = $this->lockEvent($tree, $eventId, $ifMatch);
            [$group, $person, $marriage] = $this->group($tree, $event);
            $places = $this->places($group, $person, $marriage, $event->getType());
            $changedAt = $this->clock->now();
            $this->assign($group, $person, $marriage, $event->getType(), null, $changedAt);
            foreach ($places as $place) {
                $this->entityManager->remove($place);
            }
            $tree->touch($changedAt);
        });
    }

    private function lockEvent(FamilyTree $tree, string $eventId, ?string $ifMatch): GenealogicalEvent
    {
        if (!Uuid::isValid($eventId)) {
            $this->notFound();
        }
        $this->entityManager->lock($tree, LockMode::PESSIMISTIC_WRITE);
        $this->entityManager->refresh($tree);
        $this->versions->assertMatches($tree, $ifMatch);
        $event = $this->events->findInTree($tree, $eventId);
        if (null === $event) {
            $this->notFound();
        }
        $this->entityManager->lock($event, LockMode::PESSIMISTIC_WRITE);

        return $event;
    }

    /** @return array{list<GenealogicalEvent>, Person|null, MarriagePeriod|null} */
    private function group(FamilyTree $tree, GenealogicalEvent $event): array
    {
        if (in_array($event->getType(), [EventType::BIRTH, EventType::CHILD_BIRTH], true)) {
            $child = EventType::BIRTH === $event->getType()
                ? $event->getPerson()
                : $this->child($tree, $event);
            $group = [];
            $birth = $this->events->findAutomaticPersonLifecycle($child, EventType::BIRTH);
            if (null !== $birth) {
                $group[] = $birth;
            }
            foreach ($this->parentLinks->findForChild($child) as $link) {
                $projection = $this->events->findAutomaticForSource(
                    $link->getParent(),
                    EventType::CHILD_BIRTH,
                    $link->getId(),
                );
                if (null !== $projection) {
                    $group[] = $projection;
                }
            }

            return [$group, $child, null];
        }

        if (
            EventSource::AUTOMATIC === $event->getSource()
            && in_array($event->getType(), [EventType::MARRIAGE, EventType::DIVORCE], true)
        ) {
            $sourceId = $event->getSourceEntityId();
            $marriage = null === $sourceId
                ? null
                : $this->marriages->findInTree($tree, $sourceId->toRfc4122());
            if (null === $marriage || null === $sourceId) {
                $this->notFound();
            }

            return [$this->events->findAutomaticGroup($event->getType(), $sourceId), null, $marriage];
        }

        if (EventSource::AUTOMATIC === $event->getSource() && null !== $event->getSourceEntityId()) {
            return [
                $this->events->findAutomaticGroup($event->getType(), $event->getSourceEntityId()),
                EventType::DEATH === $event->getType() ? $event->getPerson() : null,
                null,
            ];
        }

        return [[$event], null, null];
    }

    private function child(FamilyTree $tree, GenealogicalEvent $event): Person
    {
        $childId = $event->getData()['childId'] ?? null;
        $child = is_string($childId) && Uuid::isValid($childId)
            ? $this->people->findInTree($tree, $childId)
            : null;
        if (null === $child) {
            $this->notFound();
        }

        return $child;
    }

    /**
     * @param list<GenealogicalEvent> $group
     *
     * @return list<EventPlace>
     */
    private function places(array $group, ?Person $person, ?MarriagePeriod $marriage, EventType $type): array
    {
        $places = [];
        $canonical = match ($type) {
            EventType::BIRTH, EventType::CHILD_BIRTH => $person?->getBirthPlace(),
            EventType::DEATH                         => $person?->getDeathPlace(),
            EventType::MARRIAGE                      => $marriage?->getMarriagePlace(),
            EventType::DIVORCE                       => $marriage?->getDissolutionPlace(),
            default                                  => null,
        };
        foreach (array_merge(
            [$canonical],
            array_map(static fn (GenealogicalEvent $event): ?EventPlace => $event->getPlace(), $group),
        ) as $place) {
            if ($place instanceof EventPlace && null !== $place->getId()) {
                $places[$place->getId()->toRfc4122()] = $place;
            }
        }

        return array_values($places);
    }

    /** @param list<GenealogicalEvent> $group */
    private function assign(
        array $group,
        ?Person $person,
        ?MarriagePeriod $marriage,
        EventType $type,
        ?EventPlace $place,
        \DateTimeImmutable $changedAt,
    ): void {
        match ($type) {
            EventType::BIRTH, EventType::CHILD_BIRTH => $person?->changeBirthPlace($place, $changedAt),
            EventType::DEATH                         => $person?->changeDeathPlace($place, $changedAt),
            EventType::MARRIAGE                      => $marriage?->changeMarriagePlace($place, $changedAt),
            EventType::DIVORCE                       => $marriage?->changeDissolutionPlace($place, $changedAt),
            default                                  => null,
        };
        foreach ($group as $event) {
            $event->changePlace($place, $changedAt);
        }
    }

    private function notFound(): never
    {
        throw new ApiException(JsonResponse::HTTP_NOT_FOUND, 'NOT_FOUND', 'not_found');
    }
}
