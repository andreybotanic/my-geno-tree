<?php

namespace App\Application\Tree\Layout;

use App\Entity\FamilyTree;
use App\Entity\MarriagePeriod;
use App\Entity\ParentLink;
use App\Entity\Person;

/**
 * Immutable, query-free structural view of a tree.
 *
 * All indexes are built once and reused by visibility and revision calculations.
 */
final readonly class TreeStructure
{
    /** @var array<string, Person> */
    private array $peopleById;

    /** @var array<string, array<string, true>> */
    private array $childrenByParent;

    /** @var array<string, array<string, true>> */
    private array $parentsByChild;

    /** @var array<string, array<string, true>> */
    private array $spousesByPerson;

    /** @var array<string, true> */
    private array $spousePairKeys;

    /** @var list<array{parentId: string, childId: string, role: string}> */
    private array $parentTuples;

    /** @var list<array{0: string, 1: string}> */
    private array $spousePairs;

    /**
     * @param list<Person>         $people
     * @param list<ParentLink>     $parentLinks
     * @param list<MarriagePeriod> $marriages
     */
    public function __construct(
        array $people,
        array $parentLinks,
        array $marriages,
    ) {
        $peopleById = [];
        foreach ($people as $person) {
            $peopleById[self::id($person)] = $person;
        }

        $childrenByParent = [];
        $parentsByChild = [];
        $parentTuples = [];
        foreach ($parentLinks as $link) {
            $parentId = self::id($link->getParent());
            $childId = self::id($link->getChild());
            $childrenByParent[$parentId][$childId] = true;
            $parentsByChild[$childId][$parentId] = true;
            $parentTuples[] = [
                'parentId' => $parentId,
                'childId'  => $childId,
                'role'     => $link->getRole()->value,
            ];
        }
        usort($parentTuples, static fn (array $left, array $right): int => [
            $left['parentId'],
            $left['childId'],
            $left['role'],
        ] <=> [
            $right['parentId'],
            $right['childId'],
            $right['role'],
        ]);

        $spousesByPerson = [];
        $spousePairsByKey = [];
        foreach ($marriages as $marriage) {
            $first = self::id($marriage->getMalePerson());
            $second = self::id($marriage->getFemalePerson());
            $spousesByPerson[$first][$second] = true;
            $spousesByPerson[$second][$first] = true;
            [$primary, $secondary] = self::canonicalIds($first, $second);
            $spousePairsByKey[$primary . ':' . $secondary] = [$primary, $secondary];
        }
        $spousePairs = array_values($spousePairsByKey);
        usort($spousePairs, static fn (array $left, array $right): int => $left <=> $right);

        $this->peopleById = $peopleById;
        $this->childrenByParent = $childrenByParent;
        $this->parentsByChild = $parentsByChild;
        $this->spousesByPerson = $spousesByPerson;
        $this->spousePairKeys = array_fill_keys(array_keys($spousePairsByKey), true);
        $this->parentTuples = $parentTuples;
        $this->spousePairs = $spousePairs;
    }

    /** @return array{0: Person, 1: Person|null}|null */
    public function effectiveAnchor(FamilyTree $tree): ?array
    {
        $primary = $tree->getReferencePrimaryPerson();
        if (null !== $primary) {
            $primary = $this->peopleById[self::id($primary)] ?? null;
            if (null === $primary) {
                return null;
            }

            $secondary = $tree->getReferenceSecondaryPerson();
            if (null === $secondary) {
                return [$primary, null];
            }
            $secondary = $this->peopleById[self::id($secondary)] ?? null;

            return null === $secondary ? null : $this->canonicalPeople($primary, $secondary);
        }

        if ([] === $this->peopleById) {
            return null;
        }

        $people = array_values($this->peopleById);
        usort($people, static fn (Person $left, Person $right): int => [
            $left->getCreatedAt()->format('U.u'),
            self::id($left),
        ] <=> [
            $right->getCreatedAt()->format('U.u'),
            self::id($right),
        ]);

        return [$people[0], null];
    }

    /** @return list<string> */
    public function visiblePersonIds(Person $primary, ?Person $secondary): array
    {
        $visible = $this->visibleFor(self::id($primary));
        if (null !== $secondary) {
            foreach ($this->visibleFor(self::id($secondary)) as $personId => $_present) {
                $visible[$personId] = true;
            }
        }

        $ids = array_keys($visible);
        sort($ids);

        return $ids;
    }

    public function revision(Person $primary, ?Person $secondary): string
    {
        [$primary, $secondary] = null === $secondary
            ? [$primary, null]
            : $this->canonicalPeople($primary, $secondary);
        $visibleIds = $this->visiblePersonIds($primary, $secondary);
        $visible = array_fill_keys($visibleIds, true);

        $lines = [
            null === $secondary
                ? 'anchor:person:' . self::id($primary)
                : 'anchor:marriage:' . self::id($primary) . ':' . self::id($secondary),
        ];
        foreach ($visibleIds as $personId) {
            $lines[] = 'person:' . $personId;
        }
        foreach ($this->parentTuples as $link) {
            if (isset($visible[$link['parentId']], $visible[$link['childId']])) {
                $lines[] = sprintf(
                    'parent:%s:%s:%s',
                    $link['parentId'],
                    $link['childId'],
                    $link['role'],
                );
            }
        }
        foreach ($this->spousePairs as [$first, $second]) {
            if (isset($visible[$first], $visible[$second])) {
                $lines[] = sprintf('spouse:%s:%s', $first, $second);
            }
        }

        return rtrim(strtr(base64_encode(hash('sha256', implode("\n", $lines), true)), '+/', '-_'), '=');
    }

    public function hasMarriage(Person $first, Person $second): bool
    {
        [$primaryId, $secondaryId] = self::canonicalIds(self::id($first), self::id($second));

        return isset($this->spousePairKeys[$primaryId . ':' . $secondaryId]);
    }

    /** @return array<string, true> */
    private function visibleFor(string $anchorId): array
    {
        $visible = [$anchorId => true];

        $pending = array_keys($this->parentsByChild[$anchorId] ?? []);
        while ([] !== $pending) {
            $ancestorId = array_pop($pending);
            if (isset($visible[$ancestorId])) {
                continue;
            }
            $visible[$ancestorId] = true;
            array_push($pending, ...array_keys($this->parentsByChild[$ancestorId] ?? []));
        }

        $descendants = [];
        $pending = array_keys($this->childrenByParent[$anchorId] ?? []);
        while ([] !== $pending) {
            $descendantId = array_pop($pending);
            if (isset($descendants[$descendantId])) {
                continue;
            }
            $descendants[$descendantId] = true;
            $this->addChildWithKnownParents($descendantId, $visible);
            array_push($pending, ...array_keys($this->childrenByParent[$descendantId] ?? []));
        }

        foreach (array_keys($this->parentsByChild[$anchorId] ?? []) as $parentId) {
            foreach ($this->siblingsOf($parentId) as $siblingId => $_present) {
                $visible[$siblingId] = true;
                foreach (array_keys($this->childrenByParent[$siblingId] ?? []) as $childId) {
                    $this->addChildWithKnownParents($childId, $visible);
                }
            }
        }

        foreach ($this->siblingsOf($anchorId) as $siblingId => $_present) {
            $this->addChildWithKnownParents($siblingId, $visible);
        }
        foreach (array_keys($this->spousesByPerson[$anchorId] ?? []) as $spouseId) {
            $visible[$spouseId] = true;
        }

        foreach (array_keys($this->spousesByPerson[$anchorId] ?? []) as $spouseId) {
            foreach (array_keys($this->parentsByChild[$spouseId] ?? []) as $parentId) {
                $visible[$parentId] = true;
            }
            foreach ($this->siblingsOf($spouseId) as $siblingId => $_present) {
                $visible[$siblingId] = true;
            }
        }

        foreach (array_keys($this->spousesByPerson[$anchorId] ?? []) as $spouseId) {
            foreach (array_keys($this->childrenByParent[$spouseId] ?? []) as $childId) {
                if ($childId !== $anchorId) {
                    $this->addChildWithKnownParents($childId, $visible);
                }
            }
        }

        foreach ($this->siblingsOf($anchorId) as $siblingId => $_present) {
            foreach (array_keys($this->childrenByParent[$siblingId] ?? []) as $childId) {
                $this->addChildWithKnownParents($childId, $visible);
            }
        }

        return $visible;
    }

    /**
     * @param array<string, true> $visible
     */
    private function addChildWithKnownParents(string $childId, array &$visible): void
    {
        $visible[$childId] = true;
        foreach (array_keys($this->parentsByChild[$childId] ?? []) as $parentId) {
            $visible[$parentId] = true;
        }
    }

    /** @return array<string, true> */
    private function siblingsOf(string $personId): array
    {
        $siblings = [];
        foreach (array_keys($this->parentsByChild[$personId] ?? []) as $parentId) {
            foreach (array_keys($this->childrenByParent[$parentId] ?? []) as $siblingId) {
                $siblings[$siblingId] = true;
            }
        }
        unset($siblings[$personId]);

        return $siblings;
    }

    /** @return array{0: Person, 1: Person} */
    private function canonicalPeople(Person $first, Person $second): array
    {
        return self::id($first) < self::id($second)
            ? [$first, $second]
            : [$second, $first];
    }

    /** @return array{0: string, 1: string} */
    private static function canonicalIds(string $first, string $second): array
    {
        return $first < $second ? [$first, $second] : [$second, $first];
    }

    private static function id(Person $person): string
    {
        return $person->getId()->toRfc4122();
    }
}
