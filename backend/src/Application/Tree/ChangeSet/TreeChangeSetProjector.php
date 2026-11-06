<?php

namespace App\Application\Tree\ChangeSet;

use App\Api\ApiException;
use App\Application\Tree\GraphInvariantPolicy;
use App\Entity\FamilyTree;
use App\Entity\MarriagePeriod;
use App\Entity\ParentLink;
use App\Entity\Person;
use App\Repository\MarriagePeriodRepository;
use App\Repository\ParentLinkRepository;
use App\Repository\PersonRepository;

/** Builds and validates the complete prospective graph without persisting any genealogy. */
final readonly class TreeChangeSetProjector
{
    public function __construct(
        private PersonRepository $people,
        private ParentLinkRepository $parents,
        private MarriagePeriodRepository $marriages,
        private GraphInvariantPolicy $invariants,
    ) {
    }

    public function project(
        FamilyTree $tree,
        TreeChangeSet $changes,
        \DateTimeImmutable $now,
        bool $refresh = false,
    ): ProjectedTreeChangeSet {
        $originals = $people = [];
        foreach ($this->people->findAllForChangeSet($tree, $refresh) as $person) {
            $id = $person->getId()->toRfc4122();
            $originals[$id] = $person;
            $people[$id] = clone $person;
        }
        $references = $changedIds = [];
        $hasChanges = false;
        foreach ($changes->people as $change) {
            if ('' === $change->reference || isset($references[$change->reference])) {
                $this->conflict('CHANGE_REFERENCE_DUPLICATE');
            }
            $references[$change->reference] = true;
            $data = $change->data;
            if (null !== $data->birthPlace || null !== $data->deathPlace) {
                $this->conflict('CHANGE_PLACE_NOT_SUPPORTED');
            }
            if (null === $change->existingPersonId) {
                $hasChanges = true;
                if (isset($people[$change->reference])) {
                    $this->conflict('CHANGE_REFERENCE_DUPLICATE');
                }
                $person = new Person(
                    $tree,
                    $data->birthSurname,
                    $data->givenName,
                    $data->patronymic,
                    $data->gender,
                    $data->birthDate,
                    $data->deathDate,
                    $now,
                );
            } else {
                $id = $change->existingPersonId;
                if (!isset($originals[$id])) {
                    $this->conflict('CHANGE_PERSON_NOT_IN_TREE');
                }
                if (isset($changedIds[$id]) || (isset($people[$change->reference]) && $change->reference !== $id)) {
                    $this->conflict('CHANGE_REFERENCE_DUPLICATE');
                }
                $changedIds[$id] = true;
                $hasChanges = $hasChanges || $change->changes($originals[$id]);
                $person = $people[$id];
                $person->update(
                    $data->birthSurname,
                    $data->givenName,
                    $data->patronymic,
                    $data->gender,
                    $data->birthDate,
                    $data->deathDate,
                    $now,
                );
            }
            $people[$change->reference] = $person;
        }
        $parents = $addedParents = [];
        foreach ($this->parents->findAllStructuralForTree($tree, $refresh) as $link) {
            $parents[] = new ParentLink(
                $tree,
                $people[$link->getParent()->getId()->toRfc4122()],
                $people[$link->getChild()->getId()->toRfc4122()],
                $link->getRole(),
            );
        }
        foreach ($changes->parents as $change) {
            $parent = $this->resolve($people, $change->parent);
            $child = $this->resolve($people, $change->child);
            $duplicate = false;
            foreach ($parents as $link) {
                if ($link->getParent() === $parent && $link->getChild() === $child
                    && $link->getRole() === $change->role) {
                    $duplicate = true;
                    break;
                }
            }
            if (!$duplicate) {
                $parents[] = $addedParents[] = new ParentLink($tree, $parent, $child, $change->role);
            }
        }
        $marriages = $addedMarriages = [];
        foreach ($this->marriages->findAllStructuralForTree($tree, $refresh) as $marriage) {
            $marriages[] = new MarriagePeriod(
                $tree,
                $people[$marriage->getMalePerson()->getId()->toRfc4122()],
                $people[$marriage->getFemalePerson()->getId()->toRfc4122()],
                $marriage->getMarriageDate(),
                $marriage->getDissolutionDate(),
                $marriage->getCreatedAt(),
            );
        }
        foreach ($changes->marriages as $change) {
            [$male, $female] = $this->invariants->marriagePeople(
                $this->resolve($people, $change->first),
                $this->resolve($people, $change->second),
            );
            $duplicate = false;
            foreach ($marriages as $marriage) {
                if ($marriage->getMalePerson() !== $male || $marriage->getFemalePerson() !== $female) {
                    continue;
                }
                if ($marriage->getMarriageDate() == $change->marriageDate
                    && $marriage->getDissolutionDate() == $change->dissolutionDate) {
                    $duplicate = true;
                    break;
                }
                if (!$change->newPeriod) {
                    $this->conflict('MARRIAGE_PERIOD_CHOICE_REQUIRED');
                }
            }
            if (!$duplicate) {
                $marriages[] = $addedMarriages[] = new MarriagePeriod(
                    $tree,
                    $male,
                    $female,
                    $change->marriageDate,
                    $change->dissolutionDate,
                    $now,
                );
            }
        }
        $this->validate($people, $parents, $marriages);

        return new ProjectedTreeChangeSet(
            $people,
            $originals,
            $parents,
            $marriages,
            $addedParents,
            $addedMarriages,
            $hasChanges || [] !== $addedParents || [] !== $addedMarriages,
        );
    }

    /** @param array<string, Person> $people */
    private function resolve(array $people, string $reference): Person
    {
        return $people[$reference]
            ?? throw new ApiException(422, 'CHANGE_REFERENCE_UNKNOWN', 'change_reference_unknown');
    }

    /**
     * @param array<string, Person> $people
     * @param list<ParentLink>      $parents
     * @param list<MarriagePeriod>  $marriages
     */
    private function validate(array $people, array $parents, array $marriages): void
    {
        $adjacency = $children = $indegree = $roles = [];
        foreach ($people as $person) {
            $id = $person->getId()->toRfc4122();
            $adjacency[$id] = [];
            $children[$id] = [];
            $indegree[$id] = 0;
            $birth = $person->getBirthDate()->earliest();
            $death = $person->getDeathDate()?->latest();
            if (null !== $birth && null !== $death && $death < $birth) {
                $this->conflict('DEATH_BEFORE_BIRTH');
            }
        }
        foreach ($parents as $link) {
            $parent = $link->getParent();
            $child = $link->getChild();
            $this->invariants->assertParentRoles($parent, $child, $link->getRole());
            $this->invariants->assertParentChronology($parent, $child);
            $p = $parent->getId()->toRfc4122();
            $c = $child->getId()->toRfc4122();
            $roleKey = $c . ':' . $link->getRole()->value;
            if (isset($roles[$roleKey])) {
                $this->conflict('PARENT_ROLE_ALREADY_ASSIGNED');
            }
            $roles[$roleKey] = true;
            $adjacency[$p][] = $c;
            $adjacency[$c][] = $p;
            $children[$p][] = $c;
            ++$indegree[$c];
        }
        foreach ($marriages as $marriage) {
            [$male, $female] = $this->invariants->marriagePeople(
                $marriage->getMalePerson(),
                $marriage->getFemalePerson(),
            );
            if ($male !== $marriage->getMalePerson()) {
                $this->conflict('MARRIAGE_GENDER_CONFLICT');
            }
            $this->invariants->assertMarriageDates(
                $male,
                $female,
                $marriage->getMarriageDate(),
                $marriage->getDissolutionDate(),
            );
            $m = $male->getId()->toRfc4122();
            $f = $female->getId()->toRfc4122();
            $adjacency[$m][] = $f;
            $adjacency[$f][] = $m;
        }
        $pending = array_keys(array_filter($indegree, static fn (int $count): bool => 0 === $count));
        $processed = 0;
        while ([] !== $pending) {
            $current = array_pop($pending);
            ++$processed;
            foreach ($children[$current] as $next) {
                if (0 === --$indegree[$next]) {
                    $pending[] = $next;
                }
            }
        }
        if ($processed !== count($indegree)) {
            $this->conflict('ANCESTOR_CYCLE');
        }
        if ([] === $adjacency) {
            return;
        }
        $pending = [array_key_first($adjacency)];
        $visited = [];
        while ([] !== $pending) {
            $current = array_pop($pending);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            foreach ($adjacency[$current] as $next) {
                $pending[] = $next;
            }
        }
        if (count($visited) !== count($adjacency)) {
            $this->conflict('TREE_WOULD_DISCONNECT');
        }
    }

    private function conflict(string $code): never
    {
        throw new ApiException(422, $code, strtolower($code));
    }
}
