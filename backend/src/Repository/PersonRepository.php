<?php

namespace App\Repository;

use App\Entity\FamilyTree;
use App\Entity\Person;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Person>
 */
final class PersonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Person::class);
    }

    /**
     * @return list<Person>
     */
    public function findAllForTree(FamilyTree $tree): array
    {
        return $this->createQueryBuilder('person')
            ->addSelect('photoAttachment', 'photoMedia', 'photoVariant')
            ->leftJoin('person.photoAttachment', 'photoAttachment')
            ->leftJoin('photoAttachment.media', 'photoMedia')
            ->leftJoin('photoMedia.variants', 'photoVariant')
            ->andWhere('person.tree = :tree')
            ->setParameter('tree', $tree)
            ->orderBy('person.createdAt', 'ASC')
            ->addOrderBy('person.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findInTree(FamilyTree $tree, string $id): ?Person
    {
        return $this->createQueryBuilder('person')
            ->addSelect('photoAttachment', 'photoMedia', 'photoVariant')
            ->leftJoin('person.photoAttachment', 'photoAttachment')
            ->leftJoin('photoAttachment.media', 'photoMedia')
            ->leftJoin('photoMedia.variants', 'photoVariant')
            ->andWhere('person.tree = :tree')
            ->andWhere('person.id = :id')
            ->setParameter('tree', $tree)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countForTree(FamilyTree $tree): int
    {
        return (int)$this->createQueryBuilder('person')
            ->select('COUNT(person.id)')
            ->andWhere('person.tree = :tree')
            ->setParameter('tree', $tree)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
