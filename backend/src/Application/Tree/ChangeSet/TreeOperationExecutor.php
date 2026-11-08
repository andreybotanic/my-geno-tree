<?php

namespace App\Application\Tree\ChangeSet;

use App\Api\ApiException;
use App\Application\Event\AutomaticEventSynchronizer;
use App\Application\Event\EventPlaceData;
use App\Application\Event\EventPlaceDataPolicy;
use App\Application\Event\EventPlaceService;
use App\Application\Event\ManualEventService;
use App\Application\Media\MediaService;
use App\Application\Tree\TreeVersion;
use App\Entity\EventPlace;
use App\Entity\FamilyTree;
use App\Entity\MarriagePeriod;
use App\Entity\MediaObject;
use App\Entity\Person;
use App\Entity\Value\EventType;
use App\Repository\MarriagePeriodRepository;
use App\Repository\ParentLinkRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Executes already validated operations within the owning change-set transaction. */
final readonly class TreeOperationExecutor
{
    public function __construct(
        private ManualEventService $manual,
        private EventPlaceService $eventPlaces,
        private EventPlaceDataPolicy $placeData,
        private AutomaticEventSynchronizer $automatic,
        private ParentLinkRepository $parents,
        private MarriagePeriodRepository $marriages,
        private MediaService $media,
        private TreeVersion $versions,
        private EntityManagerInterface $em,
    ) {
    }

    /** @return list<MediaObject> */
    public function remove(
        FamilyTree $tree,
        ProjectedTreeChangeSet $projection,
        \DateTimeImmutable $now,
    ): array {
        $deleted = array_fill_keys($projection->removedPersonIds, true);
        $parents = $projection->removedParents;
        $marriages = $projection->removedMarriages;
        foreach ($this->parents->findAllStructuralForTree($tree) as $link) {
            if (isset($deleted[$link->getParent()->getId()->toRfc4122()])
                || isset($deleted[$link->getChild()->getId()->toRfc4122()])) {
                $parents[] = $link;
            }
        }
        foreach ($this->marriages->findAllStructuralForTree($tree) as $marriage) {
            if (isset($deleted[$marriage->getMalePerson()->getId()->toRfc4122()])
                || isset($deleted[$marriage->getFemalePerson()->getId()->toRfc4122()])) {
                $marriages[] = $marriage;
            }
        }
        foreach ([...$parents, ...$marriages] as $row) {
            $this->automatic->removeForSource($row->getId());
            $this->em->remove($row);
        }
        $removedMedia = [];
        foreach ($projection->removedPersonIds as $id) {
            $person = $projection->originals[$id];
            foreach ($projection->events as $event) {
                if ($event->getPerson() === $person) {
                    if (EventType::CHILD_BIRTH !== $event->getType() && null !== $event->getPlace()) {
                        $this->em->remove($event->getPlace());
                    }
                    $this->em->remove($event);
                }
            }
            $photo = $this->media->detachPersonPhotoRecord($person, $now);
            if (null !== $photo) {
                $removedMedia[] = $photo;
            }
            $this->em->remove($person);
        }
        $primary = $tree->getReferencePrimaryPerson();
        $secondary = $tree->getReferenceSecondaryPerson();
        if ((null !== $primary && isset($deleted[$primary->getId()->toRfc4122()]))
            || (null !== $secondary && isset($deleted[$secondary->getId()->toRfc4122()]))) {
            $survivor = null !== $primary && !isset($deleted[$primary->getId()->toRfc4122()]) ? $primary : $secondary;
            if (null === $survivor || isset($deleted[$survivor->getId()->toRfc4122()])) {
                $first = array_key_first($projection->people);
                $survivor = null === $first ? null : ($projection->originals[$first] ?? $projection->people[$first]);
            }
            null === $survivor ? $tree->clearReference() : $tree->referencePerson($survivor);
        } elseif (null !== $primary && null !== $secondary) {
            $pairRemains = false;
            foreach ($projection->marriages as $marriage) {
                $ids = [
                    $marriage->getMalePerson()->getId()->toRfc4122(),
                    $marriage->getFemalePerson()->getId()->toRfc4122(),
                ];
                $pairRemains = $pairRemains || (
                    in_array($primary->getId()->toRfc4122(), $ids, true)
                    && in_array($secondary->getId()->toRfc4122(), $ids, true)
                );
            }
            if (!$pairRemains) {
                $tree->referencePerson($primary);
            }
        }

        return $removedMedia;
    }

    /** @param array<'birthPlace'|'deathPlace', EventPlaceData|null> $places */
    public function personPlaces(Person $person, array $places, \DateTimeImmutable $now): void
    {
        foreach ($places as $field => $data) {
            $old = 'birthPlace' === $field ? $person->getBirthPlace() : $person->getDeathPlace();
            $place = $this->place($data, $old);
            'birthPlace' === $field ? $person->changeBirthPlace($place, $now) : $person->changeDeathPlace($place, $now);
        }
    }

    /** @param array<'marriagePlace'|'dissolutionPlace', EventPlaceData|null> $places */
    public function marriagePlaces(MarriagePeriod $marriage, array $places, \DateTimeImmutable $now): void
    {
        foreach ($places as $field => $data) {
            $old = 'marriagePlace' === $field ? $marriage->getMarriagePlace() : $marriage->getDissolutionPlace();
            $place = $this->place($data, $old);
            'marriagePlace' === $field
                ? $marriage->changeMarriagePlace($place, $now) : $marriage->changeDissolutionPlace($place, $now);
        }
    }

    /** @param array<string, Person> $people
     * @return list<string>
     */
    public function events(
        FamilyTree $tree,
        TreeChangeSet $changes,
        ProjectedTreeChangeSet $projection,
        array $people,
    ): array {
        $affected = [];
        foreach ($changes->operations as $operation) {
            $etag = $this->versions->etag($tree);
            if ('create_events' === $operation->type) {
                $participants = [];
                foreach ($operation->strings('personIds') as $reference) {
                    $projected = $projection->people[$reference];
                    $person = $people[$projected->getId()->toRfc4122()];
                    $participants[] = $person;
                    $affected[] = $person->getId()->toRfc4122();
                }
                $this->manual->createMany($tree, $participants, $operation->object('event'), $etag);
            } elseif ('reorder_events' === $operation->type) {
                $person = $people[$projection->people[$operation->string('personId')]->getId()->toRfc4122()];
                $this->manual->reorder($tree, $person, ['eventIds' => $operation->strings('eventIds')], $etag);
                $affected[] = $person->getId()->toRfc4122();
            } elseif (in_array($operation->type, ['update_event', 'delete_event', 'set_event_place'], true)) {
                $id = $operation->string('eventId');
                $event = $projection->events[$id] ?? throw new ApiException(404, 'TREE_RESOURCE_NOT_FOUND');
                $person = $event->getPerson();
                $affected[] = $person->getId()->toRfc4122();
                if ('update_event' === $operation->type) {
                    $this->manual->update($tree, $person, $id, $operation->object('fields'), $etag);
                } elseif ('delete_event' === $operation->type) {
                    $this->manual->delete($tree, $person, $id, $etag);
                } elseif (null === $operation->arguments['place']) {
                    $this->eventPlaces->delete($tree, $id, $etag);
                } else {
                    $this->eventPlaces->put($tree, $id, $operation->object('place'), $etag);
                }
            }
        }

        return array_values(array_unique($affected));
    }

    public function updateMarriage(
        MarriagePeriod $marriage,
        MarriagePeriod $projected,
        TreeChangeSet $changes,
        \DateTimeImmutable $now,
    ): void {
        $old = null === $projected->getDissolutionDate() ? $marriage->getDissolutionPlace() : null;
        $marriage->changeDates($projected->getMarriageDate(), $projected->getDissolutionDate(), $now);
        if (null !== $old) {
            $this->em->remove($old);
        }
        foreach ($changes->operations as $operation) {
            if ('update_marriage_period' !== $operation->type
                || $operation->string('marriageId') !== $marriage->getId()->toRfc4122()) {
                continue;
            }
            $places = [];
            foreach ($operation->object('fields') as $field => $value) {
                if (in_array($field, ['marriagePlace', 'dissolutionPlace'], true)) {
                    $places[$field] = null === $value ? null : $this->placeData->resolved($value, $field);
                }
            }
            $this->marriagePlaces($marriage, $places, $now);
        }
        $this->automatic->synchronizeMarriageDates($marriage, $now);
    }

    private function place(?EventPlaceData $data, ?EventPlace $old): ?EventPlace
    {
        if (null === $data) {
            if (null !== $old) {
                $this->em->remove($old);
            }

            return null;
        }
        $place = $old ?? EventPlace::resolved($data->name, $data->latitude, $data->longitude);
        $place->resolve($data->name, $data->latitude, $data->longitude);
        $this->em->persist($place);

        return $place;
    }
}
