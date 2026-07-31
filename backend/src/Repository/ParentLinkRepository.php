<?php

namespace App\Repository;

use App\Entity\ParentLink;
use App\Entity\FamilyTree;
use App\Entity\Person;
use App\Entity\Value\ParentRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ParentLink>
 */
final class ParentLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParentLink::class);
    }

    /**
     * @return list<ParentLink>
     */
    public function findAllForTree(FamilyTree $tree): array
    {
        return $this->createQueryBuilder('link')
            ->addSelect('parent', 'child')
            ->join('link.parent', 'parent')
            ->join('link.child', 'child')
            ->andWhere('link.tree = :tree')
            ->setParameter('tree', $tree)
            ->orderBy('link.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findInTree(FamilyTree $tree, string $id): ?ParentLink
    {
        return $this->findOneBy(['tree' => $tree, 'id' => $id]);
    }

    public function findForChildRole(
        Person $child,
        ParentRole $role,
    ): ?ParentLink {
        return $this->findOneBy(['child' => $child, 'role' => $role]);
    }

    public function findForPair(
        Person $parent,
        Person $child,
    ): ?ParentLink {
        return $this->findOneBy(['parent' => $parent, 'child' => $child]);
    }
}
