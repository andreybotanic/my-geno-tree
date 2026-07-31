<?php

namespace App\Application\Tree;

use App\Api\ApiException;
use App\Application\Event\AutomaticEventSynchronizer;
use App\Application\Media\MediaService;
use App\Application\Person\PersonDataPolicy;
use App\Application\Person\PersonLookup;
use App\Entity\FamilyTree;
use App\Entity\MarriagePeriod;
use App\Entity\MediaObject;
use App\Entity\ParentLink;
use App\Entity\Person;
use App\Entity\Value\Gender;
use App\Entity\Value\ParentRole;
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
        private PersonLookup $personLookup,
        private GraphInvariantPolicy $invariants,
        private ParentLinkRepository $parentLinks,
        private MarriagePeriodRepository $marriages,
        private TreeVersion $versions,
        private AutomaticEventSynchronizer $events,
        private MediaService $media,
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
            'coordinateUpdates',
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
        $this->movePeople(
            $tree,
            $payload['coordinateUpdates'],
            $changedAt,
        );
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
            'coordinateUpdates',
        ]);
        $parents = $this->parentSpecifications($tree, $payload['parents']);
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
        $this->movePeople(
            $tree,
            $payload['coordinateUpdates'],
            $changedAt,
        );
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
            'coordinateUpdates',
        ]);
        $parent = $this->person($tree, $payload['parentId'], 'parentId');
        $child = $this->person($tree, $payload['childId'], 'childId');
        $role = $this->parentRole($payload['role']);
        $this->assignParentGender($parent, $role, $changedAt);
        $this->createParentLink($tree, $parent, $child, $role, $changedAt);
        $this->movePeople(
            $tree,
            $payload['coordinateUpdates'],
            $changedAt,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function addMarriage(
        FamilyTree $tree,
        array $payload,
        \DateTimeImmutable $changedAt,
    ): void {
        $this->assertExactKeys($payload, [
            'type',
            'personId',
            'relative',
            'marriageDate',
            'dissolutionDate',
            'maleNewSurname',
            'femaleNewSurname',
            'coordinateUpdates',
        ]);
        $person = $this->person($tree, $payload['personId'], 'personId');
        $partner = $this->relative(
            $tree,
            $payload['relative'],
            $changedAt,
        );
        [$male, $female] = $this->invariants->marriagePeople(
            $person,
            $partner,
        );
        $marriageDate = $this->personData->dateValue(
            $payload['marriageDate'],
            'marriageDate',
        );
        $dissolutionDate = $this->personData->optionalDateValue(
            $payload['dissolutionDate'],
            'dissolutionDate',
        );
        $this->invariants->assertMarriageDates(
            $male,
            $female,
            $marriageDate,
            $dissolutionDate,
        );
        $maleSurname = $this->optionalSurname(
            $payload['maleNewSurname'],
            'maleNewSurname',
        );
        $femaleSurname = $this->optionalSurname(
            $payload['femaleNewSurname'],
            'femaleNewSurname',
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
        $this->events->synchronizeMarriage(
            $marriage,
            $maleSurname,
            $femaleSurname,
            $changedAt,
        );
        $this->movePeople(
            $tree,
            $payload['coordinateUpdates'],
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
            'coordinateUpdates',
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
        $this->movePeople(
            $tree,
            $payload['coordinateUpdates'],
            $changedAt,
        );
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
            'coordinateUpdates',
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
        $this->events->removeForSource($marriage->getId());
        $this->entityManager->remove($marriage);
        $this->movePeople(
            $tree,
            $payload['coordinateUpdates'],
            $changedAt,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
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
            'coordinateUpdates',
        ]);
        $person = $this->person($tree, $payload['personId'], 'personId');
        $this->invariants->assertPersonDeletionAllowed($tree, $person);
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
        $photo = $this->media->detachPersonPhotoRecord($person, $changedAt);
        if ($photo instanceof MediaObject) {
            $removedMedia[] = $photo;
        }
        $this->entityManager->remove($person);
        $this->movePeople(
            $tree,
            $payload['coordinateUpdates'],
            $changedAt,
            $person,
        );
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
    ): Person {
        $payload = $this->object($value, 'relative');
        if (array_key_exists('personId', $payload)) {
            $this->assertExactKeys($payload, ['personId'], 'relative');
            $person = $this->person(
                $tree,
                $payload['personId'],
                'relative.personId',
            );
            if (null !== $assignedGender) {
                $this->assignGender($person, $assignedGender, $changedAt);
            }

            return $person;
        }

        $this->assertExactKeys(
            $payload,
            ['person', 'coordinates'],
            'relative',
        );
        $data = $this->personData->create($payload['person']);
        if (null !== $assignedGender) {
            if (Gender::UNKNOWN === $data->gender) {
                $data = new \App\Application\Person\PersonData(
                    $data->birthSurname,
                    $data->givenName,
                    $data->patronymic,
                    $assignedGender,
                    $data->birthDate,
                    $data->deathDate,
                );
            } elseif ($assignedGender !== $data->gender) {
                $this->invalid('relative.person.gender', 'parent_role_gender_conflict');
            }
        }
        $coordinates = $this->personData->coordinates(
            $payload['coordinates'],
        );
        $person = new Person(
            $tree,
            $data->birthSurname,
            $data->givenName,
            $data->patronymic,
            $data->gender,
            $data->birthDate,
            $data->deathDate,
            $coordinates->x,
            $coordinates->y,
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
            $this->assertExactKeys($payload, ['personId', 'role'], $field);
            $person = $this->person(
                $tree,
                $payload['personId'],
                $field . '.personId',
            );
            $role = $this->parentRole($payload['role'], $field . '.role');
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

    private function movePeople(
        FamilyTree $tree,
        mixed $value,
        \DateTimeImmutable $changedAt,
        ?Person $excluded = null,
    ): void {
        if (!is_array($value) || !array_is_list($value)) {
            $this->invalid('coordinateUpdates', 'array_required');
        }
        $seen = [];
        foreach ($value as $index => $update) {
            $field = 'coordinateUpdates.' . $index;
            $payload = $this->object($update, $field);
            $this->assertExactKeys(
                $payload,
                ['personId', 'coordinates'],
                $field,
            );
            $person = $this->person(
                $tree,
                $payload['personId'],
                $field . '.personId',
            );
            if ($person === $excluded) {
                $this->invalid($field . '.personId', 'deleted_person_not_allowed');
            }
            $id = $person->getId()->toRfc4122();
            if (isset($seen[$id])) {
                $this->invalid($field . '.personId', 'duplicate_coordinate_update');
            }
            $seen[$id] = true;
            $coordinates = $this->personData->coordinates(
                $payload['coordinates'],
            );
            $person->moveTo($coordinates->x, $coordinates->y, $changedAt);
        }
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

        return $value;
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

    private function invalid(string $field, string $error): never
    {
        throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'GRAPH_MUTATION_INVALID', 'graph_mutation_invalid', [$field => [$error]]);
    }

    private function notFound(): never
    {
        throw new ApiException(JsonResponse::HTTP_NOT_FOUND, 'NOT_FOUND', 'not_found');
    }
}
