<?php

namespace App\Application\Tree;

use App\Api\ApiException;
use App\Api\MarriagePeriodViewFactory;
use App\Api\ParentLinkViewFactory;
use App\Api\PersonViewFactory;
use App\Entity\FamilyTree;
use App\Entity\MarriagePeriod;
use App\Entity\ParentLink;
use App\Entity\Person;
use App\Repository\MarriagePeriodRepository;
use App\Repository\ParentLinkRepository;
use App\Repository\PersonRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Uid\Uuid;

final readonly class SearchRoutePlanService
{
    public function __construct(
        private PersonRepository $people,
        private ParentLinkRepository $parentLinks,
        private MarriagePeriodRepository $marriages,
        private PersonViewFactory $personViews,
        private ParentLinkViewFactory $parentLinkViews,
        private MarriagePeriodViewFactory $marriageViews,
        private TreeVersion $versions,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(FamilyTree $tree, string $personId): array
    {
        if (!Uuid::isValid($personId) || !$this->people->existsInTree($tree, $personId)) {
            throw new ApiException(JsonResponse::HTTP_NOT_FOUND, 'NOT_FOUND', 'not_found');
        }

        $references = $this->referenceIds($tree);
        if ([] === $references) {
            throw new ApiException(JsonResponse::HTTP_CONFLICT, 'TREE_REFERENCE_REQUIRED', 'tree_reference_required');
        }

        $parentLinks = $this->parentLinks->findAllForTree($tree);
        $marriages = $this->marriages->findAllForTree($tree);
        $result = $this->shortestPath(
            $references,
            $personId,
            $parentLinks,
            $marriages,
        );
        if (null === $result) {
            throw new ApiException(JsonResponse::HTTP_NOT_FOUND, 'ROUTE_NOT_FOUND', 'route_not_found');
        }

        [$nodes, $path] = $result;
        $includedNodes = array_fill_keys(array_keys($nodes), true);
        $includedParents = [];
        $includedMarriages = [];
        $linksByChild = [];
        foreach ($parentLinks as $link) {
            $linksByChild[$this->id($link->getChild())][] = $link;
        }

        foreach ($path as $edge) {
            if ('marriage' === $edge['type'] && $edge['relation'] instanceof MarriagePeriod) {
                $includedMarriages[$edge['relation']->getId()->toRfc4122()] = $edge['relation'];
                continue;
            }
            if (!$edge['relation'] instanceof ParentLink) {
                continue;
            }
            /** @var ParentLink $link */
            $link = $edge['relation'];
            $childId = $this->id($link->getChild());
            foreach ($linksByChild[$childId] ?? [] as $parentLink) {
                $includedParents[$parentLink->getId()->toRfc4122()] = $parentLink;
                $parentId = $this->id($parentLink->getParent());
                if (!isset($includedNodes[$parentId])) {
                    $includedNodes[$parentId] = true;
                    $nodes[$parentId] = [
                        'generation' => $nodes[$childId]['generation'] - 1,
                        'kind'       => 'support',
                    ];
                }
            }
        }

        foreach ($path as $edge) {
            if ('parent' === $edge['type'] && $edge['relation'] instanceof ParentLink) {
                /** @var ParentLink $link */
                $link = $edge['relation'];
                $includedParents[$link->getId()->toRfc4122()] = $link;
            }
        }
        $this->includeFamilyMarriages($includedParents, $includedMarriages, $marriages);

        ksort($nodes, SORT_STRING);
        ksort($includedParents, SORT_STRING);
        ksort($includedMarriages, SORT_STRING);
        $peopleById = [];
        foreach ($this->people->findPresentationPeopleByTreeAndIds($tree, array_keys($nodes)) as $person) {
            $peopleById[$this->id($person)] = $person;
        }
        $includedPeople = array_map(
            fn (string $id): array => $this->personViews->create($peopleById[$id]),
            array_keys($nodes),
        );

        return [
            'etag'           => $this->versions->etag($tree),
            'targetPersonId' => $personId,
            'people'         => $includedPeople,
            'nodes'          => array_map(static fn (string $id, array $node): array => [
                'personId'   => $id,
                'generation' => $node['generation'],
                'kind'       => $node['kind'],
            ], array_keys($nodes), array_values($nodes)),
            'parentLinks' => array_map(
                $this->parentLinkViews->create(...),
                array_values($includedParents),
            ),
            'marriages' => array_map(
                $this->marriageViews->create(...),
                array_values($includedMarriages),
            ),
            'familyBlocks' => $this->familyBlocks(
                array_values($includedParents),
                array_values($includedMarriages),
            ),
        ];
    }

    /**
     * @param list<string>         $references
     * @param list<ParentLink>     $parentLinks
     * @param list<MarriagePeriod> $marriages
     *
     * @return array{
     *     array<string, array{
     *         generation: int,
     *         kind: string
     *     }>,
     *     list<array{
     *         type: string,
     *         relation: ParentLink|MarriagePeriod
     *     }>
     * }|null
     */
    private function shortestPath(
        array $references,
        string $targetId,
        array $parentLinks,
        array $marriages,
    ): ?array {
        /**
         * @var array<string, list<array{
         *     node: string,
         *     type: string,
         *     relation: ParentLink|MarriagePeriod,
         *     generationOffset: int
         * }>> $neighbors
         */
        $neighbors = [];
        foreach ($parentLinks as $link) {
            $parentId = $this->id($link->getParent());
            $childId = $this->id($link->getChild());
            $neighbors[$parentId][] = [
                'node' => $childId, 'type' => 'parent', 'relation' => $link, 'generationOffset' => 1,
            ];
            $neighbors[$childId][] = [
                'node' => $parentId, 'type' => 'parent', 'relation' => $link, 'generationOffset' => -1,
            ];
        }
        $firstMarriageByPair = [];
        foreach ($marriages as $marriage) {
            $first = $this->id($marriage->getMalePerson());
            $second = $this->id($marriage->getFemalePerson());
            $key = $this->pairKey($first, $second);
            if (!isset($firstMarriageByPair[$key])
                || $marriage->getId()->toRfc4122() < $firstMarriageByPair[$key]->getId()->toRfc4122()) {
                $firstMarriageByPair[$key] = $marriage;
            }
        }
        foreach ($firstMarriageByPair as $marriage) {
            $first = $this->id($marriage->getMalePerson());
            $second = $this->id($marriage->getFemalePerson());
            $neighbors[$first][] = [
                'node' => $second, 'type' => 'marriage', 'relation' => $marriage, 'generationOffset' => 0,
            ];
            $neighbors[$second][] = [
                'node' => $first, 'type' => 'marriage', 'relation' => $marriage, 'generationOffset' => 0,
            ];
        }
        foreach ($neighbors as &$entries) {
            usort($entries, static fn (array $left, array $right): int => (
                $left['node'] <=> $right['node']
                ?: $left['type'] <=> $right['type']
                ?: $left['relation']->getId()->toRfc4122() <=> $right['relation']->getId()->toRfc4122()
            ));
        }
        unset($entries);

        sort($references, SORT_STRING);
        $nodes = [];
        $previous = [];
        $pending = [];
        foreach ($references as $reference) {
            $nodes[$reference] = ['generation' => 0, 'kind' => 'route'];
            $pending[] = $reference;
        }
        for ($position = 0; isset($pending[$position]); ++$position) {
            $current = $pending[$position];
            if ($current === $targetId) {
                break;
            }
            foreach ($neighbors[$current] ?? [] as $edge) {
                if (isset($nodes[$edge['node']])) {
                    continue;
                }
                $nodes[$edge['node']] = [
                    'generation' => $nodes[$current]['generation'] + $edge['generationOffset'],
                    'kind'       => 'route',
                ];
                $previous[$edge['node']] = ['personId' => $current, ...$edge];
                $pending[] = $edge['node'];
            }
        }
        if (!isset($nodes[$targetId])) {
            return null;
        }
        $path = [];
        $coreNodes = [$targetId => $nodes[$targetId]];
        for ($current = $targetId; isset($previous[$current]);) {
            $edge = $previous[$current];
            $path[] = ['type' => $edge['type'], 'relation' => $edge['relation']];
            $coreNodes[$edge['personId']] = $nodes[$edge['personId']];
            $current = $edge['personId'];
        }

        return [$coreNodes, array_reverse($path)];
    }

    /**
     * @param array<string, ParentLink>     $parentLinks
     * @param array<string, MarriagePeriod> $includedMarriages
     * @param list<MarriagePeriod>          $marriages
     */
    private function includeFamilyMarriages(
        array $parentLinks,
        array &$includedMarriages,
        array $marriages,
    ): void {
        $byChild = [];
        foreach ($parentLinks as $link) {
            $byChild[$this->id($link->getChild())][] = $link;
        }
        $pairs = [];
        foreach ($byChild as $links) {
            if (2 === count($links)) {
                $pairs[$this->pairKey(
                    $this->id($links[0]->getParent()),
                    $this->id($links[1]->getParent()),
                )] = true;
            }
        }
        foreach ($marriages as $marriage) {
            if (isset($pairs[$this->pairKey(
                $this->id($marriage->getMalePerson()),
                $this->id($marriage->getFemalePerson()),
            )])) {
                $includedMarriages[$marriage->getId()->toRfc4122()] = $marriage;
            }
        }
    }

    /**
     * @param list<ParentLink>     $parentLinks
     * @param list<MarriagePeriod> $marriages
     *
     * @return list<array{id: string, personIds: array{string, string}, childIds: list<string>, married: bool}>
     */
    private function familyBlocks(array $parentLinks, array $marriages): array
    {
        $byChild = [];
        foreach ($parentLinks as $link) {
            $byChild[$this->id($link->getChild())][] = $link;
        }
        $blocks = [];
        foreach ($byChild as $childId => $links) {
            if (2 !== count($links)) {
                continue;
            }
            $firstPersonId = $this->id($links[0]->getParent());
            $secondPersonId = $this->id($links[1]->getParent());
            $personIds = $firstPersonId < $secondPersonId
                ? [$firstPersonId, $secondPersonId]
                : [$secondPersonId, $firstPersonId];
            $key = implode(':', $personIds);
            $blocks[$key] ??= [
                'id'        => $key,
                'personIds' => $personIds,
                'childIds'  => [],
                'married'   => false,
            ];
            $blocks[$key]['childIds'][] = $childId;
        }
        foreach ($marriages as $marriage) {
            $key = $this->pairKey($this->id($marriage->getMalePerson()), $this->id($marriage->getFemalePerson()));
            if (isset($blocks[$key])) {
                $blocks[$key]['married'] = true;
            }
        }
        foreach ($blocks as &$block) {
            sort($block['childIds'], SORT_STRING);
        }
        unset($block);
        ksort($blocks, SORT_STRING);

        return array_values($blocks);
    }

    /** @return list<string> */
    private function referenceIds(FamilyTree $tree): array
    {
        $primary = $tree->getReferencePrimaryPerson();
        if (null === $primary) {
            return [];
        }

        return array_values(array_filter([
            $this->id($primary),
            null === $tree->getReferenceSecondaryPerson() ? null : $this->id($tree->getReferenceSecondaryPerson()),
        ]));
    }

    private function id(Person $person): string
    {
        return $person->getId()->toRfc4122();
    }

    private function pairKey(string $first, string $second): string
    {
        return $first < $second ? $first . ':' . $second : $second . ':' . $first;
    }
}
