<?php

namespace App\Api;

use App\Api\View\EventView;
use App\Api\View\MarriagePeriodView;
use App\Api\View\ParentLinkView;
use App\Api\View\PersonDetailsView;
use App\Api\View\PersonView;
use App\Api\View\RelationshipPersonView;
use App\Api\View\RelationshipsView;
use App\Api\View\TreeSnapshotView;
use App\Api\View\TreeSummaryView;
use App\Api\View\TreeView;
use App\Application\Event\EventTimelineService;
use App\Application\Tree\GraphInvariantPolicy;
use App\Application\Tree\Layout\TreeLayoutSnapshotService;
use App\Application\Tree\Layout\TreeStructure;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Entity\GenealogicalEvent;
use App\Entity\MarriagePeriod;
use App\Entity\ParentLink;
use App\Entity\Person;
use App\Repository\MarriagePeriodRepository;
use App\Repository\ParentLinkRepository;
use App\Repository\PersonRepository;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

/** Loads bounded aggregates and maps their entities to typed response DTOs. */
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
        private ObjectMapperInterface $mapper,
    ) {
    }

    public function summary(FamilyTree $tree, ?int $personCount = null): TreeSummaryView
    {
        $view = $this->mapper->map($tree, TreeView::class);

        return new TreeSummaryView(
            $view->id,
            $view->name,
            $personCount ?? $this->people->countForTree($tree),
            $view->createdAt,
            $view->updatedAt,
            $this->versions->etag($tree),
            $view->reference,
        );
    }

    public function snapshot(FamilyTree $tree): TreeSnapshotView
    {
        $people = $this->people->findAllForTree($tree);
        $parentLinks = $this->parentLinks->findAllForTree($tree);
        $marriages = $this->marriages->findAllForTree($tree);
        $layout = $this->layouts->create($tree, new TreeStructure($people, $parentLinks, $marriages));

        return new TreeSnapshotView(
            $this->mapper->map($tree, TreeView::class),
            $this->versions->etag($tree),
            $layout['layoutRevision'],
            $layout['layout'],
            $tree->getBloodNetworkState(),
            array_map(fn (Person $person): PersonView => $this->mapper->map($person, PersonView::class), $people),
            array_map(
                fn (ParentLink $link): ParentLinkView => $this->mapper->map($link, ParentLinkView::class),
                $parentLinks,
            ),
            array_map(
                fn (MarriagePeriod $marriage): MarriagePeriodView => $this->mapper->map(
                    $marriage,
                    MarriagePeriodView::class,
                ),
                $marriages,
            ),
        );
    }

    public function personDetails(FamilyTree $tree, Person $person): PersonDetailsView
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

        return new PersonDetailsView(
            $this->versions->etag($tree),
            $this->mapper->map($person, PersonView::class),
            new RelationshipsView(
                array_map(
                    fn (Person $related): RelationshipPersonView => $this->mapper->map(
                        $related,
                        RelationshipPersonView::class,
                    ),
                    array_values($relatedPeople),
                ),
                array_map(
                    fn (ParentLink $link): ParentLinkView => $this->mapper->map($link, ParentLinkView::class),
                    $links,
                ),
                array_map(
                    fn (MarriagePeriod $marriage): MarriagePeriodView => $this->mapper->map(
                        $marriage,
                        MarriagePeriodView::class,
                    ),
                    $marriages,
                ),
            ),
            array_map(
                fn (GenealogicalEvent $event): EventView => $this->mapper->map($event, EventView::class),
                $this->timeline->forPerson($person),
            ),
            ['canEdit' => true, ...$permissions],
        );
    }
}
