<?php

namespace App\Api;

use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Repository\PersonRepository;
use App\Repository\MarriagePeriodRepository;
use App\Repository\ParentLinkRepository;

final readonly class TreeSnapshotViewFactory
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
    public function create(FamilyTree $tree): array
    {
        return [
            'tree' => [
                'id' => $tree->getId()->toRfc4122(),
                'name' => $tree->getName(),
                'createdAt' => $tree->getCreatedAt()->format(DATE_ATOM),
                'updatedAt' => $tree->getUpdatedAt()->format(DATE_ATOM),
            ],
            'etag' => $this->versions->etag($tree),
            'people' => array_map(
                $this->personViews->create(...),
                $this->people->findAllForTree($tree),
            ),
            'parentLinks' => array_map(
                $this->parentLinkViews->create(...),
                $this->parentLinks->findAllForTree($tree),
            ),
            'marriages' => array_map(
                $this->marriageViews->create(...),
                $this->marriages->findAllForTree($tree),
            ),
        ];
    }
}
