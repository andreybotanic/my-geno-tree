<?php

namespace App\Application\TreeExchange;

use App\Entity\FamilyTree;

final readonly class ImportedTree
{
    public function __construct(
        public FamilyTree $tree,
        public int $personCount,
    ) {
    }
}
