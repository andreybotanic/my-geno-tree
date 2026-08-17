<?php

namespace App\Application\Tree;

use App\Api\ApiException;
use App\Application\Event\AutomaticEventSynchronizer;
use App\Application\Event\EventPlaceData;
use App\Application\Event\EventPlaceDataPolicy;
use App\Application\Media\MediaService;
use App\Application\Person\PersonDataPolicy;
use App\Application\Person\PersonLookup;
use App\Application\Tree\Layout\TreeLayoutInvalidator;
use App\Entity\EventPlace;
use App\Entity\FamilyTree;
use App\Entity\MarriagePeriod;
use App\Entity\MediaObject;
use App\Entity\ParentLink;
use App\Entity\Person;
use App\Entity\Value\Gender;
use App\Entity\Value\GenealogicalDate;
use App\Entity\Value\KnownText;
use App\Entity\Value\ParentRole;
use App\Repository\GenealogicalEventRepository;
use App\Repository\MarriagePeriodRepository;
use App\Repository\ParentLinkRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class StructuralGraphMutationService
{
    public function __construct(
        private PersonDataPolicy $personData,
        private EventPlaceDataPolicy $placeData,
        private PersonLookup $personLookup,
        private GraphInvariantPolicy $invariants,
        private ParentLinkRepository $parentLinks,
        private MarriagePeriodRepository $marriages,
        private TreeVersion $versions,
        private AutomaticEventSynchronizer $events,
        private GenealogicalEventRepository $eventRepository,
        private MediaService $media,
        private TreeLayoutInvalidator $layoutInvalidator,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function mutate(
        FamilyTree $tree,
        array $payload,
        ?string $ifMatch,
    ): void {
        $type = $payload['type'] ?? null;
        if (!is_string($type)) {
            $this->invalid('type', 'graph_mutation_type_invalid');
        }

        $removedMedia = [];
        $this->entityManager->wrapInTransaction(function () use (
            $tree,
            $payload,
            $type,
            $ifMatch,
            &$removedMedia,
        ): void {
            $this->entityManager->lock($tree, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($tree);
            $this->versions->assertMatches($tree, $ifMatch);
            $changedAt = $this->clock->now();

            match ($type) {
                'add_parents'       => $this->addParents($tree, $payload, $changedAt),
                'add_parent'        => $this->addParent($tree, $payload, $changedAt),
                'add_child'         => $this->addChild($tree, $payload, $changedAt),
                'link_parent_child' => $this->linkParentChild(
                    $tree,
                    $payload,
                    $changedAt,
                ),
                'add_marriage_period' => $this->addMarriage(
                    $tree,
                    $payload,
                    $changedAt,
                ),
                'remove_parent_link' => $this->removeParentLink(
                    $tree,
                    $payload,
                    $changedAt,
                ),
                'remove_marriage_period' => $this->removeMarriage(
                    $tree,
                    $payload,
                    $changedAt,
                ),
                'delete_person' => $this->deletePerson(
                    $tree,
                    $payload,
                    $changedAt,
                    $removedMedia,
                ),
                default => $this->invalid(
                    'type',
                    'graph_mutation_type_invalid',
                ),
            };
            $tree->touch($changedAt);
            $this->entityManager->flush();
            $this->layoutInvalidator->invalidateChanged($tree);
        });
        $this->media->deleteStored($removedMedia);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function addParent(
        FamilyTree $tree,
        array $payload,
        \DateTimeImmutable $changedAt,
    ): void {
        $this->assertExactKeys($payload, [
            'type',
            'childId',
            'role',
            'relative',
        ]);
        $child = $this->person($tree, $payload['childId'], 'childId');
        $role = $this->parentRole($payload['role']);
        $parent = $this->relative(
            $tree,
            $payload['relative'],
            $changedAt,
            $role->requiredGender(),
        );
        $this->createParentLink($tree, $parent, $child, $role, $changedAt);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function addParents(
        FamilyTree $tree,
        array $payload,
        \DateTimeImmutable $changedAt,
    ): void {
        $detailed = 2 !== count($payload);
        if (!$detailed) {
            $this->assertExactKeys($payload, ['type', 'childId']);
        } else {
            $this->assertExactKeys($payload, [
                'type',
                'childId',
                'father',
                'mother',
                'marriage',
            ]);
        }

        $child = $this->person($tree, $payload['childId'], 'childId');
        if (!$detailed) {
            $father = $this->anonymousPerson($tree, Gender::MALE, $changedAt);
            $mother = $this->anonymousPerson($tree, Gender::FEMALE, $changedAt);
        } else {
            $father = $this->newPerson(
                $tree,
                $payload['father'],
                $changedAt,
                Gender::MALE,
                'father',
            );
            $mother = $this->newPerson(
                $tree,
                $payload['mother'],
                $changedAt,
                Gender::FEMALE,
                'mother',
            );
        }

        $this->createParentLink(
            $tree,
            $father,
            $child,
            ParentRole::FATHER,
            $changedAt,
        );
        $this->createParentLink(
            $tree,
            $mother,
            $child,
            ParentRole::MOTHER,
            $changedAt,
        );

        if ($detailed && null !== $payload['marriage']) {
            $marriage = $this->object($payload['marriage'], 'marriage');
            $this->assertKeys($marriage, [
                'marriageDate',
                'dissolutionDate',
                'maleNewSurname',
                'femaleNewSurname',
            ], ['marriagePlace', 'dissolutionPlace'], 'marriage');
            $this->createMarriage(
                $tree,
                $father,
                $mother,
                $marriage,
                $changedAt,
                'marriage.',
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function addChild(
        FamilyTree $tree,
        array $payload,
        \DateTimeImmutable $changedAt,
    ): void {
        $this->assertExactKeys($payload, [
            'type',
            'parents',
            'relative',
        ]);
        $parents = $this->parentSpecifications($tree, $payload['parents'], $changedAt);
        $child = $this->relative(
            $tree,
            $payload['relative'],
            $changedAt,
        );
        foreach ($parents as [$parent, $role]) {
            $this->assignParentGender($parent, $role, $changedAt);
            $this->createParentLink(
                $tree,
                $parent,
                $child,
                $role,
                $changedAt,
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function linkParentChild(
        FamilyTree $tree,
        array $payload,
        \DateTimeImmutable $changedAt,
    ): void {
        $this->assertExactKeys($payload, [
            'type',
            'parentId',
            'childId',
            'role',
        ]);
        $parent = $this->person($tree, $payload['parentId'], 'parentId');
        $child = $this->person($tree, $payload['childId'], 'childId');
        $role = $this->parentRole($payload['role']);
        $this->assignParentGender($parent, $role, $changedAt);
        $this->createParentLink($tree, $parent, $child, $role, $changedAt);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function addMarriage(
        FamilyTree $tree,
        array $payload,
        \DateTimeImmutable $changedAt,
    ): void {
        $this->assertKeys($payload, [
            'type',
            'personId',
            'relative',
            'marriageDate',
            'dissolutionDate',
            'maleNewSurname',
            'femaleNewSurname',
        ], ['marriagePlace', 'dissolutionPlace']);
        $person = $this->person($tree, $payload['personId'], 'personId');
        $partner = $this->relative(
            $tree,
            $payload['relative'],
            $changedAt,
        );
        $this->createMarriage(
            $tree,
            $person,
            $partner,
            $payload,
            $changedAt,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function removeParentLink(
        FamilyTree $tree,
        array $payload,
        \DateTimeImmutable $changedAt,
    ): void {
        $this->assertExactKeys($payload, [
            'type',
            'parentLinkId',
        ]);
        $id = $this->id($payload['parentLinkId'], 'parentLinkId');
        $link = $this->parentLinks->findInTree($tree, $id);
        if (null === $link) {
            $this->notFound();
        }
        $this->invariants->assertConnectedAfter(
            $tree,
            removedParentLink: $link,
        );
        $this->events->removeForSource($link->getId());
        $this->entityManager->remove($link);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function removeMarriage(
        FamilyTree $tree,
        array $payload,
        \DateTimeImmutable $changedAt,
    ): void {
        $this->assertExactKeys($payload, [
            'type',
            'marriageId',
        ]);
        $id = $this->id($payload['marriageId'], 'marriageId');
        $marriage = $this->marriages->findInTree($tree, $id);
        if (null === $marriage) {
            $this->notFound();
        }
        $this->invariants->assertConnectedAfter(
            $tree,
            removedMarriage: $marriage,
        );
        $this->replaceReferenceAfterMarriageRemoval($tree, $marriage);
        $this->events->removeForSource($marriage->getId());
        $this->entityManager->remove($marriage);
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<MediaObject>    $removedMedia
     */
    private function deletePerson(
        FamilyTree $tree,
        array $payload,
        \DateTimeImmutable $changedAt,
        array &$removedMedia,
    ): void {
        $this->assertExactKeys($payload, [
            'type',
            'personId',
        ]);
        $person = $this->person($tree, $payload['personId'], 'personId');
        $this->invariants->assertPersonDeletionAllowed($tree, $person);
        $this->replaceReferenceAfterPersonRemoval($tree, $person);
        foreach ($this->parentLinks->findAllForTree($tree) as $link) {
            if ($link->getParent() === $person || $link->getChild() === $person) {
                $this->events->removeForSource($link->getId());
                $this->entityManager->remove($link);
            }
        }
        foreach ($this->marriages->findForPerson($person) as $marriage) {
            $this->events->removeForSource($marriage->getId());
            $this->entityManager->remove($marriage);
        }
        $places = [];
        foreach ($this->eventRepository->findTimeline($person) as $event) {
            $place = $event->getPlace();
            $placeId = $place?->getId();
            if (null !== $place && null !== $placeId) {
                $places[$placeId->toRfc4122()] = $place;
            }
        }
        foreach ($places as $place) {
            $this->entityManager->remove($place);
        }
        $photo = $this->media->detachPersonPhotoRecord($person, $changedAt);
        if ($photo instanceof MediaObject) {
            $removedMedia[] = $photo;
        }
        $this->entityManager->remove($person);
    }

    private function replaceReferenceAfterMarriageRemoval(
        FamilyTree $tree,
        MarriagePeriod $removedMarriage,
    ): void {
        $primary = $tree->getReferencePrimaryPerson();
        $secondary = $tree->getReferenceSecondaryPerson();
        if (null === $primary || null === $secondary) {
            return;
        }

        $removedPeople = [
            $removedMarriage->getMalePerson(),
            $removedMarriage->getFemalePerson(),
        ];
        if (
            !in_array($primary, $removedPeople, true)
            || !in_array($secondary, $removedPeople, true)
            || 1 < $this->marriages->countForPair($primary, $secondary)
        ) {
            return;
        }

        $tree->referencePerson(0 === random_int(0, 1) ? $primary : $secondary);
    }

    private function replaceReferenceAfterPersonRemoval(
        FamilyTree $tree,
        Person $removedPerson,
    ): void {
        if (!$tree->references($removedPerson)) {
            return;
        }

        $primary = $tree->getReferencePrimaryPerson();
        $secondary = $tree->getReferenceSecondaryPerson();
        if (null !== $primary && null !== $secondary) {
            $tree->referencePerson($secondary === $removedPerson ? $primary : $secondary);

            return;
        }

        /** @var array<string, Person> $candidates */
        $candidates = [];
        foreach ($this->parentLinks->findAllForTree($tree) as $link) {
            if ($link->getParent() === $removedPerson) {
                $candidate = $link->getChild();
                $candidates[$candidate->getId()->toRfc4122()] = $candidate;
            } elseif ($link->getChild() === $removedPerson) {
                $candidate = $link->getParent();
                $candidates[$candidate->getId()->toRfc4122()] = $candidate;
            }
        }
        foreach ($this->marriages->findForPerson($removedPerson) as $marriage) {
            $candidate = $marriage->getMalePerson() === $removedPerson
                ? $marriage->getFemalePerson()
                : $marriage->getMalePerson();
            $candidates[$candidate->getId()->toRfc4122()] = $candidate;
        }

        if ([] === $candidates) {
            $tree->clearReference();

            return;
        }

        $choices = array_values($candidates);
        $tree->referencePerson($choices[random_int(0, count($choices) - 1)]);
    }

    private function createParentLink(
        FamilyTree $tree,
        Person $parent,
        Person $child,
        ParentRole $role,
        \DateTimeImmutable $changedAt,
    ): void {
        $this->invariants->assertParentLinkAllowed(
            $tree,
            $parent,
            $child,
            $role,
        );
        $link = new ParentLink($tree, $parent, $child, $role);
        $this->entityManager->persist($link);
        $this->events->synchronizeParentLink($link, $changedAt);
    }

    private function relative(
        FamilyTree $tree,
        mixed $value,
        \DateTimeImmutable $changedAt,
        ?Gender $assignedGender = null,
        string $field = 'relative',
    ): Person {
        $payload = $this->object($value, $field);
        if (array_key_exists('personId', $payload)) {
            $this->assertExactKeys($payload, ['personId'], $field);
            $person = $this->person(
                $tree,
                $payload['personId'],
                $field . '.personId',
            );
            if (null !== $assignedGender) {
                $this->assignGender($person, $assignedGender, $changedAt);
            }

            return $person;
        }

        $this->assertExactKeys($payload, ['person'], $field);

        return $this->newPerson(
            $tree,
            $payload['person'],
            $changedAt,
            $assignedGender,
            $field . '.person',
        );
    }

    private function newPerson(
        FamilyTree $tree,
        mixed $value,
        \DateTimeImmutable $changedAt,
        ?Gender $assignedGender,
        string $field,
    ): Person {
        $data = $this->personData->create($value);
        if (null !== $assignedGender) {
            if (Gender::UNKNOWN === $data->gender) {
                $data = new \App\Application\Person\PersonData(
                    $data->birthSurname,
                    $data->givenName,
                    $data->patronymic,
                    $assignedGender,
                    $data->birthDate,
                    $data->deathDate,
                    $data->birthPlace,
                    $data->deathPlace,
                );
            } elseif ($assignedGender !== $data->gender) {
                $this->invalid($field . '.gender', 'parent_role_gender_conflict');
            }
        }
        $person = new Person(
            $tree,
            $data->birthSurname,
            $data->givenName,
            $data->patronymic,
            $data->gender,
            $data->birthDate,
            $data->deathDate,
            $changedAt,
        );
        $this->entityManager->persist($person);
        $birthPlace = $this->resolvedPlace($data->birthPlace);
        if (null !== $birthPlace) {
            $person->changeBirthPlace($birthPlace, $changedAt);
        }
        $deathPlace = $this->resolvedPlace($data->deathPlace);
        if (null !== $deathPlace) {
            $person->changeDeathPlace($deathPlace, $changedAt);
        }
        $this->events->synchronizePersonLifecycle($person, $changedAt);

        return $person;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createMarriage(
        FamilyTree $tree,
        Person $first,
        Person $second,
        array $payload,
        \DateTimeImmutable $changedAt,
        string $fieldPrefix = '',
    ): void {
        [$male, $female] = $this->invariants->marriagePeople($first, $second);
        $marriageDate = $this->personData->dateValue(
            $payload['marriageDate'],
            $fieldPrefix . 'marriageDate',
        );
        $dissolutionDate = $this->personData->optionalDateValue(
            $payload['dissolutionDate'],
            $fieldPrefix . 'dissolutionDate',
        );
        $this->invariants->assertMarriageDates(
            $male,
            $female,
            $marriageDate,
            $dissolutionDate,
        );
        $maleSurname = $this->optionalSurname(
            $payload['maleNewSurname'],
            $fieldPrefix . 'maleNewSurname',
        );
        $femaleSurname = $this->optionalSurname(
            $payload['femaleNewSurname'],
            $fieldPrefix . 'femaleNewSurname',
        );
        $marriage = new MarriagePeriod(
            $tree,
            $male,
            $female,
            $marriageDate,
            $dissolutionDate,
            $changedAt,
        );
        $this->entityManager->persist($marriage);
        $marriagePlace = array_key_exists('marriagePlace', $payload)
            && null !== $payload['marriagePlace']
            ? $this->resolvedPlace($this->placeData->resolved(
                $payload['marriagePlace'],
                $fieldPrefix . 'marriagePlace',
            ))
            : null;
        if (null !== $marriagePlace) {
            $marriage->changeMarriagePlace($marriagePlace, $changedAt);
        }
        $dissolutionPlace = array_key_exists('dissolutionPlace', $payload)
            && null !== $payload['dissolutionPlace']
            ? $this->resolvedPlace($this->placeData->resolved(
                $payload['dissolutionPlace'],
                $fieldPrefix . 'dissolutionPlace',
            ))
            : null;
        if (null === $dissolutionDate && null !== $dissolutionPlace) {
            $this->invalid($fieldPrefix . 'dissolutionPlace', 'dissolution_place_requires_dissolution');
        }
        if (null !== $dissolutionPlace) {
            $marriage->changeDissolutionPlace($dissolutionPlace, $changedAt);
        }
        $this->events->synchronizeMarriage(
            $marriage,
            $maleSurname,
            $femaleSurname,
            $changedAt,
        );
    }

    private function anonymousPerson(
        FamilyTree $tree,
        Gender $gender,
        \DateTimeImmutable $changedAt,
    ): Person {
        $person = new Person(
            $tree,
            KnownText::unknown(),
            KnownText::unknown(),
            KnownText::unknown(),
            $gender,
            GenealogicalDate::unknown(),
            null,
            $changedAt,
        );
        $this->entityManager->persist($person);
        $this->events->synchronizePersonLifecycle($person, $changedAt);

        return $person;
    }

    private function assignParentGender(
        Person $person,
        ParentRole $role,
        \DateTimeImmutable $changedAt,
    ): void {
        $this->assignGender($person, $role->requiredGender(), $changedAt);
    }

    private function assignGender(
        Person $person,
        Gender $gender,
        \DateTimeImmutable $changedAt,
    ): void {
        if (Gender::UNKNOWN === $person->getGender()) {
            $person->update(
                $person->getBirthSurname(),
                $person->getGivenName(),
                $person->getPatronymic(),
                $gender,
                $person->getBirthDate(),
                $person->getDeathDate(),
                $changedAt,
            );
        }
    }

    /**
     * @return list<array{Person, ParentRole}>
     */
    private function parentSpecifications(
        FamilyTree $tree,
        mixed $value,
        \DateTimeImmutable $changedAt,
    ): array {
        if (!is_array($value) || !array_is_list($value)) {
            $this->invalid('parents', 'array_required');
        }
        if (count($value) < 1 || count($value) > 2) {
            $this->invalid('parents', 'one_or_two_parents_required');
        }
        $result = [];
        $ids = [];
        $roles = [];
        foreach ($value as $index => $specification) {
            $field = 'parents.' . $index;
            $payload = $this->object($specification, $field);
            if (!array_key_exists('role', $payload)) {
                $this->invalid($field . '.role', 'field_required');
            }
            $role = $this->parentRole($payload['role'], $field . '.role');
            $relative = $payload;
            unset($relative['role']);
            $person = $this->relative(
                $tree,
                $relative,
                $changedAt,
                $role->requiredGender(),
                $field,
            );
            $id = $person->getId()->toRfc4122();
            if (isset($ids[$id]) || isset($roles[$role->value])) {
                $this->invalid('parents', 'parents_must_be_distinct');
            }
            $ids[$id] = true;
            $roles[$role->value] = true;
            $result[] = [$person, $role];
        }

        return $result;
    }

    private function person(
        FamilyTree $tree,
        mixed $value,
        string $field,
    ): Person {
        return $this->personLookup->require($tree, $this->id($value, $field));
    }

    private function parentRole(
        mixed $value,
        string $field = 'role',
    ): ParentRole {
        if (!is_string($value)) {
            $this->invalid($field, 'parent_role_invalid');
        }
        $role = ParentRole::tryFrom($value);
        if (null === $role) {
            $this->invalid($field, 'parent_role_invalid');
        }

        return $role;
    }

    private function optionalSurname(mixed $value, string $field): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!is_string($value)) {
            $this->invalid($field, 'text_value_required');
        }
        $value = trim($value);
        if ('' === $value || mb_strlen($value) > 100) {
            $this->invalid($field, 'text_length_invalid');
        }

        return $value;
    }

    private function resolvedPlace(?EventPlaceData $data): ?EventPlace
    {
        if (null === $data) {
            return null;
        }
        $place = EventPlace::resolved($data->name, $data->latitude, $data->longitude);
        $this->entityManager->persist($place);

        return $place;
    }

    private function id(mixed $value, string $field): string
    {
        if (!is_string($value) || !\Symfony\Component\Uid\Uuid::isValid($value)) {
            $this->invalid($field, 'uuid_required');
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            $this->invalid($field, 'object_required');
        }
        $object = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                $this->invalid($field, 'object_required');
            }
            $object[$key] = $item;
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $expected
     */
    private function assertExactKeys(
        array $payload,
        array $expected,
        string $field = '',
    ): void {
        foreach (array_keys($payload) as $key) {
            if (!in_array($key, $expected, true)) {
                $this->invalid(
                    '' === $field ? $key : $field . '.' . $key,
                    'field_not_allowed',
                );
            }
        }
        foreach ($expected as $key) {
            if (!array_key_exists($key, $payload)) {
                $this->invalid(
                    '' === $field ? $key : $field . '.' . $key,
                    'field_required',
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $required
     * @param list<string>         $optional
     */
    private function assertKeys(
        array $payload,
        array $required,
        array $optional,
        string $field = '',
    ): void {
        foreach (array_keys($payload) as $key) {
            if (!in_array($key, [...$required, ...$optional], true)) {
                $this->invalid('' === $field ? $key : $field . '.' . $key, 'field_not_allowed');
            }
        }
        foreach ($required as $key) {
            if (!array_key_exists($key, $payload)) {
                $this->invalid('' === $field ? $key : $field . '.' . $key, 'field_required');
            }
        }
    }

    private function invalid(string $field, string $error): never
    {
        throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'GRAPH_MUTATION_INVALID', 'graph_mutation_invalid', [$field => [$error]]);
    }

    private function notFound(): never
    {
        throw new ApiException(JsonResponse::HTTP_NOT_FOUND, 'NOT_FOUND', 'not_found');
    }
}
