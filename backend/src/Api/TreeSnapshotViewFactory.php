<?php

namespace App\Api;

use App\Application\Tree\Layout\TreeLayoutSnapshotService;
use App\Application\Tree\Layout\TreeStructure;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Repository\MarriagePeriodRepository;
use App\Repository\ParentLinkRepository;
use App\Repository\PersonRepository;

final readonly class TreeSnapshotViewFactory
{
    public function __construct(
        private PersonRepository $people,
        private ParentLinkRepository $parentLinks,
        private MarriagePeriodRepository $marriages,
        private PersonViewFactory $personViews,
        private ParentLinkViewFactory $parentLinkViews,
        private MarriagePeriodViewFactory $marriageViews,
        private TreeReferenceViewFactory $referenceViews,
        private TreeVersion $versions,
        private TreeLayoutSnapshotService $layouts,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(FamilyTree $tree): array
    {
        $people = $this->people->findAllForTree($tree);
        $parentLinks = $this->parentLinks->findAllForTree($tree);
        $marriages = $this->marriages->findAllForTree($tree);
        $layout = $this->layouts->create(
            $tree,
            new TreeStructure($people, $parentLinks, $marriages),
        );

        return [
            'tree' => [
                'id'        => $tree->getId()->toRfc4122(),
                'name'      => $tree->getName(),
                'createdAt' => $tree->getCreatedAt()->format(DATE_ATOM),
                'updatedAt' => $tree->getUpdatedAt()->format(DATE_ATOM),
                'reference' => $this->referenceViews->create($tree),
            ],
            'etag'              => $this->versions->etag($tree),
            'layoutRevision'    => $layout['layoutRevision'],
            'layout'            => $layout['layout'],
            'bloodNetworkState' => $tree->getBloodNetworkState(),
            'people'            => array_map(
                $this->personViews->create(...),
                $people,
            ),
            'parentLinks' => array_map(
                $this->parentLinkViews->create(...),
                $parentLinks,
            ),
            'marriages' => array_map(
                $this->marriageViews->create(...),
                $marriages,
            ),
        ];
    }
}
