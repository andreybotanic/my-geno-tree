<?php

namespace App\Application\Tree\Layout;

use App\Entity\FamilyTree;
use App\Repository\TreeLayoutRepository;

final readonly class TreeLayoutInvalidator
{
    public function __construct(
        private TreeStructureLoader $structures,
        private TreeLayoutRepository $layouts,
    ) {
    }

    public function invalidateChanged(FamilyTree $tree): void
    {
        $structure = $this->structures->load($tree);
        $invalidLayoutIds = [];
        foreach ($this->layouts->findAllForTree($tree) as $layout) {
            $primary = $layout->getPrimaryPerson();
            $secondary = $layout->getSecondaryPerson();
            if (
                null !== $secondary
                && !$structure->hasMarriage($primary, $secondary)
            ) {
                $invalidLayoutIds[] = $layout->getId();

                continue;
            }
            if (!hash_equals(
                $layout->getLayoutRevision(),
                $structure->revision($primary, $secondary),
            )) {
                $invalidLayoutIds[] = $layout->getId();
            }
        }
        $this->layouts->deleteByIds($invalidLayoutIds);
    }
}
