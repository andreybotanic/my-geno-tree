<?php

namespace App\Repository;

use App\Entity\FamilyTree;
use App\Entity\Person;
use App\Entity\TreeDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TreeDocument> */
final class TreeDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TreeDocument::class);
    }

    /** @return list<TreeDocument> */
    public function page(
        FamilyTree $tree,
        ?Person $person,
        string $scope,
        ?string $after,
        int $limit,
        bool $newestFirst = false,
    ): array {
        $query = $this->createQueryBuilder('d')
            ->addSelect('p')
            ->leftJoin('d.person', 'p')
            ->where('d.tree = :tree')
            ->andWhere('d.deleted = false')
            ->setParameter('tree', $tree)
            ->orderBy('d.id', $newestFirst ? 'DESC' : 'ASC')
            ->setMaxResults($limit);
        if ('tree' === $scope) {
            $query->andWhere('d.person IS NULL');
        } elseif (null !== $person) {
            $query
                ->andWhere('person' === $scope ? 'd.person = :person' : 'd.person = :person OR d.person IS NULL')
                ->setParameter('person', $person);
        }
        if (null !== $after) {
            $query
                ->andWhere($newestFirst ? 'd.id < :after' : 'd.id > :after')
                ->setParameter('after', $after);
        }

        /** @var list<TreeDocument> $rows */
        $rows = $query->getQuery()->getResult();

        return $rows;
    }

    public function inTree(FamilyTree $tree, string $id): ?TreeDocument
    {
        /** @var TreeDocument|null $document */
        $document = $this->createQueryBuilder('d')
            ->addSelect('p')
            ->leftJoin('d.person', 'p')
            ->where('d.tree = :tree')
            ->andWhere('d.id = :id')
            ->andWhere('d.deleted = false')
            ->setParameter('tree', $tree)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        return $document;
    }

    public function countVisible(FamilyTree $tree, ?Person $person, string $scope): int
    {
        $query = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.tree = :tree')
            ->andWhere('d.deleted = false')
            ->setParameter('tree', $tree);
        if ('tree' === $scope) {
            $query->andWhere('d.person IS NULL');
        } elseif (null !== $person) {
            $query
                ->andWhere('person' === $scope ? 'd.person = :person' : 'd.person = :person OR d.person IS NULL')
                ->setParameter('person', $person);
        }

        return (int)$query->getQuery()->getSingleScalarResult();
    }

    /** @param list<string> $ids
     * @return list<TreeDocument>
     */
    public function ready(FamilyTree $tree, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<TreeDocument> $rows */
        $rows = $this->createQueryBuilder('d')
            ->addSelect('p')
            ->leftJoin('d.person', 'p')
            ->where('d.tree = :tree')
            ->andWhere('d.id IN (:ids)')
            ->andWhere('d.deleted = false')
            ->andWhere('d.status = :status')
            ->setParameter('tree', $tree)
            ->setParameter('ids', $ids)
            ->setParameter('status', 'ready')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return $rows;
    }

    /** @return list<TreeDocument> */
    public function forTree(FamilyTree $tree): array
    {
        /** @var list<TreeDocument> $rows */
        $rows = $this->createQueryBuilder('d')
            ->where('d.tree = :tree')
            ->setParameter('tree', $tree)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function detachPerson(Person $person): void
    {
        $this->createQueryBuilder('d')
            ->update()
            ->set('d.person', 'NULL')
            ->where('d.person = :person')
            ->setParameter('person', $person)
            ->getQuery()
            ->execute();
    }

    /** @return list<array{id: \Symfony\Component\Uid\Uuid, generation: int}> */
    public function searchable(FamilyTree $tree): array
    {
        /** @var list<array{id: \Symfony\Component\Uid\Uuid, generation: int}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('d.id AS id', 'd.generation AS generation')
            ->where('d.tree = :tree')
            ->andWhere('d.deleted = false')
            ->andWhere('d.status = :status')
            ->setParameter('tree', $tree)
            ->setParameter('status', 'ready')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
