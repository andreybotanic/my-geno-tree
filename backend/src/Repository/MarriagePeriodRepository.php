<?php

namespace App\Repository;

use App\Entity\FamilyTree;
use App\Entity\MarriagePeriod;
use App\Entity\Person;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MarriagePeriod>
 */
final class MarriagePeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarriagePeriod::class);
    }

    /**
     * @return list<MarriagePeriod>
     */
    public function findAllForTree(FamilyTree $tree): array
    {
        /** @var list<MarriagePeriod> $marriages */
        $marriages = $this->createQueryBuilder('marriage')
            ->addSelect('male', 'female', 'marriagePlace', 'dissolutionPlace')
            ->join('marriage.malePerson', 'male')
            ->join('marriage.femalePerson', 'female')
            ->leftJoin('marriage.marriagePlace', 'marriagePlace')
            ->leftJoin('marriage.dissolutionPlace', 'dissolutionPlace')
            ->andWhere('marriage.tree = :tree')
            ->setParameter('tree', $tree)
            ->orderBy('marriage.createdAt', 'ASC')
            ->addOrderBy('marriage.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $marriages;
    }

    /**
     * @return list<MarriagePeriod>
     */
    public function findAllStructuralForTree(FamilyTree $tree): array
    {
        /** @var list<MarriagePeriod> $marriages */
        $marriages = $this->createQueryBuilder('marriage')
            ->andWhere('marriage.tree = :tree')
            ->setParameter('tree', $tree)
            ->orderBy('marriage.createdAt', 'ASC')
            ->addOrderBy('marriage.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $marriages;
    }

    public function findInTree(
        FamilyTree $tree,
        string $id,
    ): ?MarriagePeriod {
        /** @var MarriagePeriod|null $marriage */
        $marriage = $this->createQueryBuilder('marriage')
            ->addSelect('marriagePlace', 'dissolutionPlace')
            ->leftJoin('marriage.marriagePlace', 'marriagePlace')
            ->leftJoin('marriage.dissolutionPlace', 'dissolutionPlace')
            ->andWhere('marriage.tree = :tree')
            ->andWhere('marriage.id = :id')
            ->setParameter('tree', $tree)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        return $marriage;
    }

    /**
     * @return list<MarriagePeriod>
     */
    public function findForPerson(Person $person): array
    {
        /** @var list<MarriagePeriod> $marriages */
        $marriages = $this->createQueryBuilder('marriage')
            ->addSelect('male', 'female', 'marriagePlace', 'dissolutionPlace')
            ->join('marriage.malePerson', 'male')
            ->join('marriage.femalePerson', 'female')
            ->leftJoin('marriage.marriagePlace', 'marriagePlace')
            ->leftJoin('marriage.dissolutionPlace', 'dissolutionPlace')
            ->andWhere(
                'marriage.malePerson = :person OR marriage.femalePerson = :person',
            )
            ->setParameter('person', $person)
            ->getQuery()
            ->getResult();

        return $marriages;
    }

    public function countForPair(Person $first, Person $second): int
    {
        return (int)$this->createQueryBuilder('marriage')
            ->select('COUNT(marriage.id)')
            ->andWhere(
                '(marriage.malePerson = :first AND marriage.femalePerson = :second)'
                . ' OR (marriage.malePerson = :second AND marriage.femalePerson = :first)',
            )
            ->setParameter('first', $first)
            ->setParameter('second', $second)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
