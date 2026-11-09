<?php

namespace App\Api;

use App\Api\View\PersonDetailsView;
use App\Api\View\TreeReferenceView;
use App\Api\View\TreeSnapshotView;
use App\Api\View\TreeSummaryView;
use App\Application\Event\EventTimelineService;
use App\Application\Tree\GraphInvariantPolicy;
use App\Application\Tree\Layout\TreeLayoutSnapshotService;
use App\Application\Tree\Layout\TreeStructure;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Entity\MarriagePeriod;
use App\Entity\ParentLink;
use App\Entity\Person;
use App\Repository\MarriagePeriodRepository;
use App\Repository\ParentLinkRepository;
use App\Repository\PersonRepository;

/** Loads bounded aggregates; output shape and normalization belong to the DTO mappings. */
final readonly class TreeViewReader
{
    public function __construct(
        private PersonRepository $people,
        private ParentLinkRepository $parentLinks,
        private MarriagePeriodRepository $marriages,
        private EventTimelineService $timeline,
        private GraphInvariantPolicy $invariants,
        private TreeVersion $versions,
        private TreeLayoutSnapshotService $layouts,
        private ViewMapper $views,
    ) {
    }

    /** @return array<string, mixed> */
    public function summary(FamilyTree $tree, ?int $personCount = null): array
    {
        return $this->views->one(
            (object)[
                'tree'        => $tree,
                'personCount' => $personCount ?? $this->people->countForTree($tree),
                'etag'        => $this->versions->etag($tree),
                'reference'   => TreeReferenceView::reference($tree->getReferencePrimaryPerson(), $tree),
            ],
            TreeSummaryView::class,
        );
    }

    /** @return array<string, mixed> */
    public function snapshot(FamilyTree $tree): array
    {
        $people = $this->people->findAllForTree($tree);
        $parentLinks = $this->parentLinks->findAllForTree($tree);
        $marriages = $this->marriages->findAllForTree($tree);
        $layout = $this->layouts->create($tree, new TreeStructure($people, $parentLinks, $marriages));

        return $this->views->one(
            (object)[
                'tree'              => $tree,
                'etag'              => $this->versions->etag($tree),
                'layoutRevision'    => $layout['layoutRevision'],
                'layout'            => $layout['layout'],
                'bloodNetworkState' => $tree->getBloodNetworkState(),
                'people'            => $people,
                'parentLinks'       => $parentLinks,
                'marriages'         => $marriages,
            ],
            TreeSnapshotView::class,
        );
    }

    /** @return array<string, mixed> */
    public function personDetails(FamilyTree $tree, Person $person): array
    {
        $links = $this->parentLinks->findRelationshipContext($tree, $person);
        $personId = $person->getId()->toRfc4122();
        $marriages = $this->marriages->findForPerson($person);
        usort($links, static fn (ParentLink $left, ParentLink $right): int => (
            $left->getId()->toRfc4122() <=> $right->getId()->toRfc4122()
        ));
        usort($marriages, static fn (MarriagePeriod $left, MarriagePeriod $right): int => (
            $left->getId()->toRfc4122() <=> $right->getId()->toRfc4122()
        ));
        $personIds = [$personId => true];
        foreach ($links as $link) {
            $personIds[$link->getParent()->getId()->toRfc4122()] = true;
            $personIds[$link->getChild()->getId()->toRfc4122()] = true;
        }
        foreach ($marriages as $marriage) {
            $personIds[$marriage->getMalePerson()->getId()->toRfc4122()] = true;
            $personIds[$marriage->getFemalePerson()->getId()->toRfc4122()] = true;
        }
        $relatedPeople = [];
        foreach ($this->people->findRelationshipPeopleByTreeAndIds($tree, array_keys($personIds)) as $candidate) {
            $candidateId = $candidate->getId()->toRfc4122();
            if ($candidateId !== $personId) {
                $relatedPeople[$candidateId] = $candidate;
            }
        }
        ksort($relatedPeople, SORT_STRING);

        $permissions = $this->invariants->deletionPermissionsForPerson($tree, $person);
        $linkIds = array_fill_keys(array_map(
            static fn (ParentLink $link): string => (string)$link->getId(),
            $links,
        ), true);
        $marriageIds = array_fill_keys(array_map(
            static fn (MarriagePeriod $marriage): string => (string)$marriage->getId(),
            $marriages,
        ), true);
        $permissions['removableParentLinkIds'] = array_values(array_filter(
            $permissions['removableParentLinkIds'],
            static fn (string $id): bool => isset($linkIds[$id]),
        ));
        $permissions['removableMarriageIds'] = array_values(array_filter(
            $permissions['removableMarriageIds'],
            static fn (string $id): bool => isset($marriageIds[$id]),
        ));

        return $this->views->one(
            (object)[
                'etag'          => $this->versions->etag($tree),
                'person'        => $person,
                'relationships' => (object)[
                    'people'      => array_values($relatedPeople),
                    'parentLinks' => $links,
                    'marriages'   => $marriages,
                ],
                'events'      => $this->timeline->forPerson($person),
                'permissions' => ['canEdit' => true, ...$permissions],
            ],
            PersonDetailsView::class,
        );
    }
}
