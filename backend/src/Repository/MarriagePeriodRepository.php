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
    public function findAllStructuralForTree(FamilyTree $tree, bool $refresh = false): array
    {
        $query = $this->createQueryBuilder('marriage')
            ->andWhere('marriage.tree = :tree')
            ->setParameter('tree', $tree)
            ->orderBy('marriage.createdAt', 'ASC')
            ->addOrderBy('marriage.id', 'ASC')
            ->getQuery();
        if ($refresh) {
            $query->setHint(\Doctrine\ORM\Query::HINT_REFRESH, true);
        }
        /** @var list<MarriagePeriod> $marriages */
        $marriages = $query->getResult();

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

    /** @return list<array{first: string, second: string}> */
    public function findBoundedContext(FamilyTree $tree, Person $person): array
    {
        /** @var list<array{first: string, second: string}> $rows */
        $rows = $this->createQueryBuilder('marriage')
            ->select('IDENTITY(marriage.malePerson) AS first', 'IDENTITY(marriage.femalePerson) AS second')
            ->andWhere('marriage.tree = :tree')
            ->andWhere('marriage.malePerson = :person OR marriage.femalePerson = :person')
            ->setParameter('tree', $tree)
            ->setParameter('person', $person)
            ->orderBy('marriage.id', 'ASC')
            ->setMaxResults(11)
            ->getQuery()
            ->getScalarResult();

        return $rows;
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
