<?php

namespace App\Repository;

use App\Entity\FamilyTree;
use App\Entity\ParentLink;
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
        /** @var list<ParentLink> $links */
        $links = $this->createQueryBuilder('link')
            ->addSelect('parent', 'child')
            ->join('link.parent', 'parent')
            ->join('link.child', 'child')
            ->andWhere('link.tree = :tree')
            ->setParameter('tree', $tree)
            ->orderBy('link.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $links;
    }

    /**
     * @return list<ParentLink>
     */
    public function findAllStructuralForTree(FamilyTree $tree): array
    {
        /** @var list<ParentLink> $links */
        $links = $this->createQueryBuilder('link')
            ->andWhere('link.tree = :tree')
            ->setParameter('tree', $tree)
            ->orderBy('link.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $links;
    }

    public function findInTree(FamilyTree $tree, string $id): ?ParentLink
    {
        return $this->findOneBy(['tree' => $tree, 'id' => $id]);
    }

    /**
     * @return list<ParentLink>
     */
    public function findRelationshipContext(FamilyTree $tree, Person $person): array
    {
        $children = $this->createQueryBuilder('childLink')
            ->select('IDENTITY(childLink.child)')
            ->andWhere('childLink.tree = :tree')
            ->andWhere('childLink.parent = :person');

        /** @var list<ParentLink> $links */
        $links = $this->createQueryBuilder('link')
            ->addSelect('parent', 'child')
            ->join('link.parent', 'parent')
            ->join('link.child', 'child')
            ->andWhere('link.tree = :tree')
            ->andWhere(
                'link.parent = :person'
                . ' OR link.child = :person'
                . ' OR IDENTITY(link.child) IN (' . $children->getDQL() . ')',
            )
            ->setParameter('tree', $tree)
            ->setParameter('person', $person)
            ->orderBy('link.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $links;
    }

    public function findForChildRole(
        Person $child,
        ParentRole $role,
    ): ?ParentLink {
        return $this->findOneBy(['child' => $child, 'role' => $role]);
    }

    /** @return list<ParentLink> */
    public function findForChild(Person $child): array
    {
        return $this->findBy(['child' => $child]);
    }

    public function findForPair(
        Person $parent,
        Person $child,
    ): ?ParentLink {
        return $this->findOneBy(['parent' => $parent, 'child' => $child]);
    }
}
