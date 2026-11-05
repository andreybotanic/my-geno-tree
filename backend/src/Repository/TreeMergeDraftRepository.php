<?php

namespace App\Repository;

use App\Entity\FamilyTree;
use App\Entity\TreeMergeDraft;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TreeMergeDraft> */
final class TreeMergeDraftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TreeMergeDraft::class);
    }

    public function current(FamilyTree $tree): ?TreeMergeDraft
    {
        /** @var TreeMergeDraft|null $draft */
        $draft = $this->createQueryBuilder('draft')
            ->andWhere('draft.tree = :tree')
            ->setParameter('tree', $tree)
            ->getQuery()
            ->getOneOrNullResult();

        return $draft;
    }
}
