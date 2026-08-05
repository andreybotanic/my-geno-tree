<?php

namespace App\Api;

use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Repository\PersonRepository;

final readonly class FamilyTreeViewFactory
{
    public function __construct(
        private TreeVersion $versions,
        private PersonRepository $people,
        private TreeReferenceViewFactory $referenceViews,
    ) {
    }

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     personCount: int,
     *     createdAt: string,
     *     updatedAt: string,
     *     etag: string,
     *     reference: array<string, mixed>|null
     * }
     */
    public function create(
        FamilyTree $tree,
        ?int $personCount = null,
    ): array {
        return [
            'id'          => $tree->getId()->toRfc4122(),
            'name'        => $tree->getName(),
            'personCount' => $personCount
                ?? $this->people->countForTree($tree),
            'createdAt' => $tree->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $tree->getUpdatedAt()->format(DATE_ATOM),
            'etag'      => $this->versions->etag($tree),
            'reference' => $this->referenceViews->create($tree),
        ];
    }
}
