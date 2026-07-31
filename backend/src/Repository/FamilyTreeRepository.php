<?php

namespace App\Repository;

use App\Entity\FamilyTree;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FamilyTree>
 */
final class FamilyTreeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FamilyTree::class);
    }

    /**
     * @return list<FamilyTree>
     */
    public function findAllForOwner(User $owner): array
    {
        return $this->createQueryBuilder('tree')
            ->where('tree.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('tree.updatedAt', 'DESC')
            ->addOrderBy('tree.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function nameExistsForOwner(
        User $owner,
        string $name,
        ?FamilyTree $except = null,
    ): bool {
        $builder = $this->createQueryBuilder('tree')
            ->select('COUNT(tree.id)')
            ->where('tree.owner = :owner')
            ->andWhere('tree.name = :name')
            ->setParameter('owner', $owner)
            ->setParameter('name', $name);

        if (null !== $except) {
            $builder
                ->andWhere('tree.id != :except')
                ->setParameter('except', $except->getId());
        }

        return 0 < (int) $builder->getQuery()->getSingleScalarResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('tree')
            ->select('COUNT(tree.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
