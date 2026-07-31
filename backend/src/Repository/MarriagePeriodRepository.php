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
        return $this->createQueryBuilder('marriage')
            ->addSelect('male', 'female')
            ->join('marriage.malePerson', 'male')
            ->join('marriage.femalePerson', 'female')
            ->andWhere('marriage.tree = :tree')
            ->setParameter('tree', $tree)
            ->orderBy('marriage.createdAt', 'ASC')
            ->addOrderBy('marriage.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findInTree(
        FamilyTree $tree,
        string $id,
    ): ?MarriagePeriod {
        return $this->findOneBy(['tree' => $tree, 'id' => $id]);
    }

    /**
     * @return list<MarriagePeriod>
     */
    public function findForPerson(Person $person): array
    {
        return $this->createQueryBuilder('marriage')
            ->andWhere(
                'marriage.malePerson = :person OR marriage.femalePerson = :person',
            )
            ->setParameter('person', $person)
            ->getQuery()
            ->getResult();
    }
}
