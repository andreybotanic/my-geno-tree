<?php

namespace App\Api;

use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Repository\PersonRepository;

final readonly class TreeSnapshotViewFactory
{
    public function __construct(
        private PersonRepository $people,
        private PersonViewFactory $personViews,
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
            'parentLinks' => [],
            'marriages' => [],
        ];
    }
}
