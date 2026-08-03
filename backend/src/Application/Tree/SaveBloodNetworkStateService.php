<?php

namespace App\Application\Tree;

use App\Entity\FamilyTree;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class SaveBloodNetworkStateService
{
    public function __construct(
        private TreeVersion $versions,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /** @param array<string, mixed> $state */
    public function save(FamilyTree $tree, array $state, ?string $ifMatch): void
    {
        $this->entityManager->wrapInTransaction(function () use ($tree, $state, $ifMatch): void {
            $this->entityManager->lock($tree, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($tree);
            $this->versions->assertMatches($tree, $ifMatch);
            $tree->saveBloodNetworkState($state, $this->clock->now());
        });
    }
}
