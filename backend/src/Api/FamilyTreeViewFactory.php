<?php

namespace App\Api;

use App\Entity\FamilyTree;
use App\Application\Tree\TreeVersion;

final readonly class FamilyTreeViewFactory
{
    public function __construct(private TreeVersion $versions)
    {
    }

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     personCount: int,
     *     createdAt: string,
     *     updatedAt: string,
     *     etag: string
     * }
     */
    public function create(FamilyTree $tree): array
    {
        return [
            'id' => $tree->getId()->toRfc4122(),
            'name' => $tree->getName(),
            'personCount' => 0,
            'createdAt' => $tree->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $tree->getUpdatedAt()->format(DATE_ATOM),
            'etag' => $this->versions->etag($tree),
        ];
    }
}
