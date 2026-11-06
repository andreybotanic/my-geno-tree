<?php

namespace App\Application\Tree;

use App\Api\ApiException;
use App\Application\Person\PersonData;
use App\Entity\FamilyTree;
use App\Entity\MarriagePeriod;
use App\Entity\ParentLink;
use App\Entity\Person;
use App\Entity\Value\Gender;
use App\Entity\Value\GenealogicalDate;
use App\Entity\Value\ParentRole;
use App\Repository\MarriagePeriodRepository;
use App\Repository\ParentLinkRepository;
use App\Repository\PersonRepository;
use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class GraphInvariantPolicy
{
    public function __construct(
        private ParentLinkRepository $parentLinks,
        private MarriagePeriodRepository $marriages,
        private PersonRepository $people,
    ) {
    }

    public function assertParentLinkAllowed(
        FamilyTree $tree,
        Person $parent,
        Person $child,
        ParentRole $role,
    ): void {
        $this->assertParentRoles($parent, $child, $role);
        if (null !== $this->parentLinks->findForPair($parent, $child)) {
            $this->conflict('PARENT_LINK_ALREADY_EXISTS');
        }
        if (null !== $this->parentLinks->findForChildRole($child, $role)) {
            $this->conflict('PARENT_ROLE_ALREADY_ASSIGNED');
        }
        if ($this->wouldCreateAncestorCycle($tree, $parent, $child)) {
            $this->conflict('ANCESTOR_CYCLE');
        }
        $this->assertParentChronology($parent, $child);
    }

    public function assertParentRoles(Person $parent, Person $child, ParentRole $role): void
    {
        if ($parent === $child) {
            $this->conflict('PERSON_CANNOT_PARENT_SELF');
        }
        if ($parent->getGender() !== $role->requiredGender()) {
            $this->invalid('role', 'parent_role_gender_conflict');
        }
    }

    public function assertParentChronology(Person $parent, Person $child): void
    {
        $this->assertDateWithinLife(
            $child->getBirthDate(),
            $parent,
            'child.birthDate',
        );
    }

    /**
     * @return array{Person, Person}
     */
    public function marriagePeople(Person $first, Person $second): array
    {
        if ($first === $second) {
            $this->conflict('PERSON_CANNOT_MARRY_SELF');
        }
        if (
            Gender::UNKNOWN === $first->getGender()
            || Gender::UNKNOWN === $second->getGender()
        ) {
            $this->invalid('personId', 'marriage_gender_required');
        }
        if ($first->getGender() === $second->getGender()) {
            $this->invalid('personId', 'marriage_gender_pair_invalid');
        }

        return Gender::MALE === $first->getGender()
            ? [$first, $second]
            : [$second, $first];
    }

    public function assertMarriageDates(
        Person $male,
        Person $female,
        GenealogicalDate $marriageDate,
        ?GenealogicalDate $dissolutionDate,
    ): void {
        if (null !== $dissolutionDate) {
            $marriageEarliest = $marriageDate->earliest();
            $dissolutionLatest = $dissolutionDate->latest();
            if (
                null !== $marriageEarliest
                && null !== $dissolutionLatest
                && $dissolutionLatest < $marriageEarliest
            ) {
                $this->invalid(
                    'dissolutionDate',
                    'dissolution_before_marriage',
                );
            }
        }
        foreach ([$male, $female] as $person) {
            $this->assertDateWithinLife(
                $marriageDate,
                $person,
                'marriageDate',
            );
            if (null !== $dissolutionDate) {
                $this->assertDateWithinLife(
                    $dissolutionDate,
                    $person,
                    'dissolutionDate',
                );
            }
        }
    }

    public function assertGenderChangeAllowed(
        Person $person,
        Gender $gender,
    ): void {
        if ($gender === $person->getGender()) {
            return;
        }
        foreach ($this->parentLinks->findAllForTree($person->getTree()) as $link) {
            if (
                $link->getParent() === $person
                && $link->getRole()->requiredGender() !== $gender
            ) {
                $this->invalid('gender', 'gender_conflicts_with_parent_role');
            }
        }
        if (
            [] !== $this->marriages->findForPerson($person)
            && Gender::UNKNOWN === $gender
        ) {
            $this->invalid('gender', 'gender_conflicts_with_marriage');
        }
        foreach ($this->marriages->findForPerson($person) as $marriage) {
            $expected = $marriage->getMalePerson() === $person
                ? Gender::MALE
                : Gender::FEMALE;
            if ($gender !== $expected) {
                $this->invalid('gender', 'gender_conflicts_with_marriage');
            }
        }
    }

    public function assertPersonDataChangeAllowed(
        Person $person,
        PersonData $data,
    ): void {
        $this->assertGenderChangeAllowed($person, $data->gender);
        foreach ($this->parentLinks->findAllForTree($person->getTree()) as $link) {
            if ($link->getParent() === $person) {
                $this->assertDateWithinLifeValues(
                    $link->getChild()->getBirthDate(),
                    $data->birthDate,
                    $data->deathDate,
                    'birthDate',
                );
            }
            if ($link->getChild() === $person) {
                $this->assertDateWithinLife(
                    $data->birthDate,
                    $link->getParent(),
                    'birthDate',
                );
            }
        }
        foreach ($this->marriages->findForPerson($person) as $marriage) {
            $this->assertDateWithinLifeValues(
                $marriage->getMarriageDate(),
                $data->birthDate,
                $data->deathDate,
                'birthDate',
            );
            $dissolutionDate = $marriage->getDissolutionDate();
            if (null !== $dissolutionDate) {
                $this->assertDateWithinLifeValues(
                    $dissolutionDate,
                    $data->birthDate,
                    $data->deathDate,
                    'deathDate',
                );
            }
        }
    }

    public function assertConnectedAfter(
        FamilyTree $tree,
        ?ParentLink $removedParentLink = null,
        ?MarriagePeriod $removedMarriage = null,
        ?Person $removedPerson = null,
    ): void {
        $people = array_values(
            array_filter(
                $this->people->findAllForTree($tree),
                static fn (Person $person): bool => $person !== $removedPerson,
            )
        );
        if (count($people) <= 1) {
            return;
        }

        /** @var array<string, array<string, true>> $adjacency */
        $adjacency = [];
        foreach ($people as $person) {
            $adjacency[$person->getId()->toRfc4122()] = [];
        }
        foreach ($this->parentLinks->findAllForTree($tree) as $link) {
            if (
                $link === $removedParentLink
                || $link->getParent() === $removedPerson
                || $link->getChild() === $removedPerson
            ) {
                continue;
            }
            $this->connect(
                $adjacency,
                $link->getParent(),
                $link->getChild(),
            );
        }
        foreach ($this->marriages->findAllForTree($tree) as $marriage) {
            if (
                $marriage === $removedMarriage
                || $marriage->getMalePerson() === $removedPerson
                || $marriage->getFemalePerson() === $removedPerson
            ) {
                continue;
            }
            $this->connect(
                $adjacency,
                $marriage->getMalePerson(),
                $marriage->getFemalePerson(),
            );
        }

        $first = array_key_first($adjacency);
        if (null === $first) {
            return;
        }
        $visited = [];
        $pending = [$first];
        while ([] !== $pending) {
            $current = array_pop($pending);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            foreach (array_keys($adjacency[$current]) as $next) {
                if (!isset($visited[$next])) {
                    $pending[] = $next;
                }
            }
        }
        if (count($visited) !== count($adjacency)) {
            $this->conflict('TREE_WOULD_DISCONNECT');
        }
    }

    public function assertPersonDeletionAllowed(
        FamilyTree $tree,
        Person $person,
    ): void {
        $hasParent = false;
        $hasChild = false;
        $parentLinks = $this->parentLinks->findAllForTree($tree);
        foreach ($parentLinks as $link) {
            $hasParent = $hasParent || $link->getChild() === $person;
            $hasChild = $hasChild || $link->getParent() === $person;
        }
        if ($hasParent && $hasChild) {
            $this->conflict('INTERNAL_PERSON_CANNOT_BE_DELETED');
        }
        $currentPartners = [];
        foreach ($this->marriages->findForPerson($person) as $marriage) {
            if (null !== $marriage->getDissolutionDate()) {
                continue;
            }
            $partner = $marriage->getMalePerson() === $person
                ? $marriage->getFemalePerson()
                : $marriage->getMalePerson();
            $currentPartners[$partner->getId()->toRfc4122()] = true;
        }
        if ([] !== $currentPartners) {
            $children = [];
            foreach ($parentLinks as $link) {
                if ($link->getParent() === $person) {
                    $children[$link->getChild()->getId()->toRfc4122()] = true;
                }
            }
            foreach ($parentLinks as $link) {
                if (
                    isset($children[$link->getChild()->getId()->toRfc4122()])
                    && isset($currentPartners[
                        $link->getParent()->getId()->toRfc4122()
                    ])
                ) {
                    $this->conflict('CURRENT_SPOUSE_COMMON_CHILD');
                }
            }
        }
        $this->assertConnectedAfter($tree, removedPerson: $person);
    }

    /**
     * @return array{canDelete: bool, removableParentLinkIds: list<string>, removableMarriageIds: list<string>}
     */
    public function deletionPermissionsForPerson(FamilyTree $tree, Person $person): array
    {
        $people = $this->people->findAllForTree($tree);
        $parentLinks = $this->parentLinks->findAllForTree($tree);
        $marriages = $this->marriages->findAllForTree($tree);
        $personId = $person->getId()->toRfc4122();
        /** @var array<string, list<array{node: string, edgeId: string}>> $adjacency */
        $adjacency = [];
        foreach ($people as $candidate) {
            $adjacency[$candidate->getId()->toRfc4122()] = [];
        }

        $hasParent = false;
        $hasChild = false;
        $children = [];
        foreach ($parentLinks as $link) {
            $parentId = $link->getParent()->getId()->toRfc4122();
            $childId = $link->getChild()->getId()->toRfc4122();
            $edgeId = 'parent:' . $link->getId()->toRfc4122();
            $this->connectEdge($adjacency, $parentId, $childId, $edgeId);
            $hasParent = $hasParent || $childId === $personId;
            $hasChild = $hasChild || $parentId === $personId;
            if ($parentId === $personId) {
                $children[$childId] = true;
            }
        }

        $currentPartners = [];
        foreach ($marriages as $marriage) {
            $maleId = $marriage->getMalePerson()->getId()->toRfc4122();
            $femaleId = $marriage->getFemalePerson()->getId()->toRfc4122();
            $edgeId = 'marriage:' . $marriage->getId()->toRfc4122();
            $this->connectEdge($adjacency, $maleId, $femaleId, $edgeId);
            if (null === $marriage->getDissolutionDate()) {
                if ($maleId === $personId) {
                    $currentPartners[$femaleId] = true;
                }
                if ($femaleId === $personId) {
                    $currentPartners[$maleId] = true;
                }
            }
        }

        $hasCurrentSpouseCommonChild = false;
        foreach ($parentLinks as $link) {
            if (
                isset($children[$link->getChild()->getId()->toRfc4122()])
                && isset($currentPartners[$link->getParent()->getId()->toRfc4122()])
            ) {
                $hasCurrentSpouseCommonChild = true;
                break;
            }
        }

        [$bridgeEdgeIds, $articulationPersonIds] = $this->criticalGraphParts($adjacency);
        $removableParentLinkIds = [];
        foreach ($parentLinks as $link) {
            $edgeId = 'parent:' . $link->getId()->toRfc4122();
            if (!isset($bridgeEdgeIds[$edgeId])) {
                $removableParentLinkIds[] = $link->getId()->toRfc4122();
            }
        }
        $removableMarriageIds = [];
        foreach ($marriages as $marriage) {
            $edgeId = 'marriage:' . $marriage->getId()->toRfc4122();
            if (!isset($bridgeEdgeIds[$edgeId])) {
                $removableMarriageIds[] = $marriage->getId()->toRfc4122();
            }
        }
        sort($removableParentLinkIds, SORT_STRING);
        sort($removableMarriageIds, SORT_STRING);

        return [
            'canDelete' => (!$hasParent || !$hasChild)
                            && !$hasCurrentSpouseCommonChild
                            && !isset($articulationPersonIds[$personId]),
            'removableParentLinkIds' => $removableParentLinkIds,
            'removableMarriageIds'   => $removableMarriageIds,
        ];
    }

    /**
     * @param array<string, list<array{node: string, edgeId: string}>> $adjacency
     */
    private function connectEdge(array &$adjacency, string $firstId, string $secondId, string $edgeId): void
    {
        $adjacency[$firstId][] = ['node' => $secondId, 'edgeId' => $edgeId];
        $adjacency[$secondId][] = ['node' => $firstId, 'edgeId' => $edgeId];
    }

    /**
     * @param array<string, list<array{node: string, edgeId: string}>> $adjacency
     *
     * @return array{array<string, true>, array<string, true>}
     */
    private function criticalGraphParts(array $adjacency): array
    {
        $time = 0;
        $visited = [];
        $depth = [];
        $lowestReachableDepth = [];
        $bridgeEdgeIds = [];
        $articulationPersonIds = [];
        $visit = null;
        $visit = function (string $personId, ?string $incomingEdgeId) use (
            &$visit,
            &$time,
            &$visited,
            &$depth,
            &$lowestReachableDepth,
            &$bridgeEdgeIds,
            &$articulationPersonIds,
            $adjacency,
        ): void {
            $visited[$personId] = true;
            $depth[$personId] = ++$time;
            $lowestReachableDepth[$personId] = $depth[$personId];
            $childCount = 0;
            foreach ($adjacency[$personId] as $edge) {
                if ($edge['edgeId'] === $incomingEdgeId) {
                    continue;
                }
                $nextPersonId = $edge['node'];
                if (!isset($visited[$nextPersonId])) {
                    ++$childCount;
                    $visit($nextPersonId, $edge['edgeId']);
                    $lowestReachableDepth[$personId] = min(
                        $lowestReachableDepth[$personId],
                        $lowestReachableDepth[$nextPersonId],
                    );
                    if ($lowestReachableDepth[$nextPersonId] > $depth[$personId]) {
                        $bridgeEdgeIds[$edge['edgeId']] = true;
                    }
                    if (
                        (null !== $incomingEdgeId && $lowestReachableDepth[$nextPersonId] >= $depth[$personId])
                        || (null === $incomingEdgeId && $childCount > 1)
                    ) {
                        $articulationPersonIds[$personId] = true;
                    }

                    continue;
                }
                $lowestReachableDepth[$personId] = min(
                    $lowestReachableDepth[$personId],
                    $depth[$nextPersonId],
                );
            }
        };
        foreach (array_keys($adjacency) as $personId) {
            if (!isset($visited[$personId])) {
                $visit($personId, null);
            }
        }

        return [$bridgeEdgeIds, $articulationPersonIds];
    }

    private function wouldCreateAncestorCycle(
        FamilyTree $tree,
        Person $parent,
        Person $child,
    ): bool {
        /** @var array<string, list<string>> $children */
        $children = [];
        foreach ($this->parentLinks->findAllForTree($tree) as $link) {
            $children[$link->getParent()->getId()->toRfc4122()][]
                = $link->getChild()->getId()->toRfc4122();
        }

        $target = $parent->getId()->toRfc4122();
        $pending = [$child->getId()->toRfc4122()];
        $visited = [];
        while ([] !== $pending) {
            $current = array_pop($pending);
            if ($current === $target) {
                return true;
            }
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            foreach ($children[$current] ?? [] as $descendant) {
                $pending[] = $descendant;
            }
        }

        return false;
    }

    private function assertDateWithinLife(
        GenealogicalDate $date,
        Person $person,
        string $field,
    ): void {
        $this->assertDateWithinLifeValues(
            $date,
            $person->getBirthDate(),
            $person->getDeathDate(),
            $field,
        );
    }

    private function assertDateWithinLifeValues(
        GenealogicalDate $date,
        GenealogicalDate $birthDate,
        ?GenealogicalDate $deathDate,
        string $field,
    ): void {
        $dateLatest = $date->latest();
        $birthEarliest = $birthDate->earliest();
        if (
            null !== $dateLatest
            && null !== $birthEarliest
            && $dateLatest < $birthEarliest
        ) {
            $this->invalid($field, 'event_before_person_birth');
        }

        $dateEarliest = $date->earliest();
        $deathLatest = $deathDate?->latest();
        if (
            null !== $dateEarliest
            && null !== $deathLatest
            && $dateEarliest > $deathLatest
        ) {
            $this->invalid($field, 'event_after_person_death');
        }
    }

    /**
     * @param array<string, array<string, true>> $adjacency
     */
    private function connect(
        array &$adjacency,
        Person $first,
        Person $second,
    ): void {
        $firstId = $first->getId()->toRfc4122();
        $secondId = $second->getId()->toRfc4122();
        $adjacency[$firstId][$secondId] = true;
        $adjacency[$secondId][$firstId] = true;
    }

    private function invalid(string $field, string $error): never
    {
        throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'GRAPH_INVARIANT_VIOLATION', 'graph_invariant_violation', [$field => [$error]]);
    }

    private function conflict(string $code): never
    {
        throw new ApiException(JsonResponse::HTTP_CONFLICT, $code, strtolower($code));
    }
}
