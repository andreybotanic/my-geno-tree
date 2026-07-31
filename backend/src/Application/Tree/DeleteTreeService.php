<?php

namespace App\Application\Tree;

use App\Entity\FamilyTree;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DeleteTreeService
{
    public function __construct(
        private TreeVersion $versions,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function delete(FamilyTree $tree, ?string $ifMatch): void
    {
        $this->entityManager->wrapInTransaction(function () use (
            $tree,
            $ifMatch,
        ): void {
            $this->entityManager->lock($tree, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($tree);
            $this->versions->assertMatches($tree, $ifMatch);
            $this->entityManager->remove($tree);
        });
    }
}
