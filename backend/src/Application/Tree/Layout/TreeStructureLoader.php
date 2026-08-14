<?php

namespace App\Application\Tree\Layout;

use App\Entity\FamilyTree;
use App\Repository\MarriagePeriodRepository;
use App\Repository\ParentLinkRepository;
use App\Repository\PersonRepository;

final readonly class TreeStructureLoader
{
    public function __construct(
        private PersonRepository $people,
        private ParentLinkRepository $parentLinks,
        private MarriagePeriodRepository $marriages,
    ) {
    }

    public function load(FamilyTree $tree): TreeStructure
    {
        return new TreeStructure(
            $this->people->findAllForTree($tree),
            $this->parentLinks->findAllForTree($tree),
            $this->marriages->findAllForTree($tree),
        );
    }
}
