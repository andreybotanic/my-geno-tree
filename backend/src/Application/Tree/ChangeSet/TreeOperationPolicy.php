<?php

namespace App\Application\Tree\ChangeSet;

use App\Api\ApiException;
use App\Application\Event\EventPlaceDataPolicy;
use App\Application\Event\ManualEventDataPolicy;
use App\Application\Person\PersonDataPolicy;
use App\Entity\FamilyTree;
use App\Entity\GenealogicalEvent;
use App\Entity\MarriagePeriod;
use App\Entity\Person;
use App\Entity\Value\EventSource;
use App\Entity\Value\EventType;
use App\Repository\GenealogicalEventRepository;

/** Validation against detached final values; never mutates managed records. */
final readonly class TreeOperationPolicy
{
    public function __construct(
        private PersonDataPolicy $dates,
        private EventPlaceDataPolicy $places,
        private ManualEventDataPolicy $manual,
        private GenealogicalEventRepository $events,
    ) {
    }

    /** @return array{people: array<string, true>, parents: array<string, true>, marriages: array<string, true>} */
    public function removals(TreeChangeSet $changes): array
    {
        $result = ['people' => [], 'parents' => [], 'marriages' => []];
        foreach ($changes->operations as $operation) {
            $target = match ($operation->type) {
                'delete_person'          => ['people', 'personId'],
                'remove_parent_link'     => ['parents', 'parentLinkId'],
                'remove_marriage_period' => ['marriages', 'marriageId'],
                default                  => null,
            };
            if (null !== $target) {
                $result[$target[0]][$operation->string($target[1])] = true;
            }
        }
        foreach ($changes->parents as $link) {
            if (isset($result['people'][$link->parent]) || isset($result['people'][$link->child])) {
                throw new ApiException(422, 'TREE_CHANGE_INVALID');
            }
        }
        foreach ($changes->marriages as $marriage) {
            if (isset($result['people'][$marriage->first]) || isset($result['people'][$marriage->second])) {
                throw new ApiException(422, 'TREE_CHANGE_INVALID');
            }
        }
        foreach ($changes->operations as $operation) {
            if ('update_marriage_period' === $operation->type
                && isset($result['marriages'][$operation->string('marriageId')])) {
                throw new ApiException(422, 'TREE_CHANGE_INVALID');
            }
        }

        return $result;
    }

    public function marriage(
        TreeChangeSet $changes,
        MarriagePeriod $original,
        MarriagePeriod $projected,
        \DateTimeImmutable $now,
    ): bool {
        foreach ($changes->operations as $operation) {
            if ('update_marriage_period' !== $operation->type
                || $operation->string('marriageId') !== $original->getId()->toRfc4122()) {
                continue;
            }
            $fields = $operation->object('fields');
            $start = array_key_exists('marriageDate', $fields)
                ? $this->dates->dateValue($fields['marriageDate'], 'marriageDate') : $original->getMarriageDate();
            $end = array_key_exists('dissolutionDate', $fields)
                ? $this->dates->optionalDateValue($fields['dissolutionDate'], 'dissolutionDate')
                : $original->getDissolutionDate();
            $projected->changeDates($start, $end, $now);
            foreach (['marriagePlace', 'dissolutionPlace'] as $field) {
                if (isset($fields[$field])) {
                    $this->places->resolved($fields[$field], $field);
                    if ('dissolutionPlace' === $field && null === $end) {
                        throw new ApiException(422, 'TREE_CHANGE_INVALID');
                    }
                }
            }

            return true;
        }

        return false;
    }

    /** @param array<string, Person> $people
     * @return array<string, GenealogicalEvent>
     */
    public function events(
        FamilyTree $tree,
        TreeChangeSet $changes,
        array $people,
        \DateTimeImmutable $now,
    ): array {
        $originals = $projected = [];
        $removals = $this->removals($changes);
        foreach ($this->events->findAllForTree($tree) as $event) {
            $id = $event->getId()->toRfc4122();
            $originals[$id] = $event;
            $source = $event->getSourceEntityId()?->toRfc4122() ?? '';
            $person = $people[$event->getPerson()->getId()->toRfc4122()] ?? null;
            $child = $event->getData()['childId'] ?? null;
            if (null !== $person && !isset($removals['parents'][$source])
                && !isset($removals['marriages'][$source])
                && !(is_string($child) && isset($removals['people'][$child]))
                && !(EventType::DEATH === $event->getType() && null === $person->getDeathDate())) {
                $projected[$id] = clone $event;
            }
        }
        foreach ($changes->operations as $operation) {
            if ('create_events' === $operation->type) {
                foreach ($operation->strings('personIds') as $id) {
                    $person = $people[$id] ?? throw new ApiException(422, 'CHANGE_REFERENCE_UNKNOWN');
                    $this->manual->event($person, $operation->object('event'));
                }
            }
            if (!in_array($operation->type, ['update_event', 'delete_event', 'set_event_place'], true)) {
                continue;
            }
            $id = $operation->string('eventId');
            $event = $projected[$id] ?? throw new ApiException(404, 'TREE_RESOURCE_NOT_FOUND');
            if ('set_event_place' === $operation->type) {
                continue;
            }
            if (EventSource::MANUAL !== $event->getSource()) {
                throw new ApiException(422, 'AUTOMATIC_EVENT_REQUIRES_SOURCE_CHANGE');
            }
            if ('delete_event' === $operation->type) {
                unset($projected[$id]);
                continue;
            }
            $person = $people[$event->getPerson()->getId()->toRfc4122()];
            $data = $this->manual->patch($person, $event, $operation->object('fields'));
            $event->changeManual($data->type, $data->date, $data->title, $data->description, $data->data, $now);
        }
        foreach ($projected as $event) {
            if (EventSource::MANUAL === $event->getSource()) {
                $this->manual->assertWithinLife($people[$event->getPerson()->getId()->toRfc4122()], $event->getDate());
            }
        }

        return $originals;
    }
}
