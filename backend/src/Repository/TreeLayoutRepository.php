<?php

namespace App\Repository;

use App\Entity\FamilyTree;
use App\Entity\Person;
use App\Entity\TreeLayout;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<TreeLayout> */
final class TreeLayoutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TreeLayout::class);
    }

    /** @return list<TreeLayout> */
    public function findAllForTree(FamilyTree $tree): array
    {
        /** @var list<TreeLayout> $layouts */
        $layouts = $this->allForTreeQuery($tree, fetchAnchorPeople: true)
            ->getQuery()
            ->getResult();

        return $layouts;
    }

    /**
     * Reads every layout under row locks so LRU selection cannot race a concurrent touch.
     *
     * @return list<TreeLayout>
     */
    public function findAllForTreeForUpdate(FamilyTree $tree): array
    {
        /** @var list<TreeLayout> $layouts */
        $layouts = $this->allForTreeQuery($tree, fetchAnchorPeople: false)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        return $layouts;
    }

    public function findForAnchor(
        FamilyTree $tree,
        Person $primary,
        ?Person $secondary,
    ): ?TreeLayout {
        $query = $this->createQueryBuilder('layout')
            ->andWhere('layout.tree = :tree')
            ->andWhere('layout.primaryPerson = :primary')
            ->setParameter('tree', $tree)
            ->setParameter('primary', $primary);
        if (null === $secondary) {
            $query->andWhere('layout.secondaryPerson IS NULL');
        } else {
            $query
                ->andWhere('layout.secondaryPerson = :secondary')
                ->setParameter('secondary', $secondary);
        }

        /** @var TreeLayout|null $layout */
        $layout = $query->getQuery()->getOneOrNullResult();

        return $layout;
    }

    private function allForTreeQuery(
        FamilyTree $tree,
        bool $fetchAnchorPeople,
    ): QueryBuilder {
        $query = $this->createQueryBuilder('layout')
            ->andWhere('layout.tree = :tree')
            ->setParameter('tree', $tree)
            ->orderBy('layout.lastUsedAt', 'ASC')
            ->addOrderBy('layout.id', 'ASC');
        if ($fetchAnchorPeople) {
            $query
                ->addSelect('primaryPerson', 'secondaryPerson')
                ->join('layout.primaryPerson', 'primaryPerson')
                ->leftJoin('layout.secondaryPerson', 'secondaryPerson');
        }

        return $query;
    }

    public function touchLastUsedAt(Uuid $id, \DateTimeImmutable $usedAt): void
    {
        $this->createQueryBuilder('layout')
            ->update()
            ->set('layout.lastUsedAt', ':usedAt')
            ->andWhere('layout.id = :id')
            ->andWhere('layout.lastUsedAt < :usedAt')
            ->setParameter('id', $id)
            ->setParameter('usedAt', $usedAt)
            ->getQuery()
            ->execute();
    }

    public function deleteById(Uuid $id): void
    {
        $this->createQueryBuilder('layout')
            ->delete()
            ->andWhere('layout.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->execute();
    }

    /** @param list<Uuid> $ids */
    public function deleteByIds(array $ids): void
    {
        if ([] === $ids) {
            return;
        }

        $this->createQueryBuilder('layout')
            ->delete()
            ->andWhere('layout.id IN (:ids)')
            ->setParameter('ids', array_map(
                static fn (Uuid $id): string => $id->toRfc4122(),
                $ids,
            ))
            ->getQuery()
            ->execute();
    }
}
