<?php

namespace App\Repository;

use App\Api\ApiException;
use App\Application\Person\PersonNameNormalizer;
use App\Entity\FamilyTree;
use App\Entity\Person;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<FamilyTree>
 */
final class FamilyTreeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly PersonNameNormalizer $normalizer)
    {
        parent::__construct($registry, FamilyTree::class);
    }

    /**
     * @return list<array{0: FamilyTree, personCount: int|string}>
     */
    public function findAllWithPersonCountsForOwner(User $owner): array
    {
        /** @var list<array{0: FamilyTree, personCount: int|string}> $trees */
        $trees = $this->createQueryBuilder('tree')
            ->select(
                'tree',
                'referencePrimary',
                'referenceSecondary',
                'COUNT(person.id) AS personCount',
            )
            ->leftJoin('tree.referencePrimaryPerson', 'referencePrimary')
            ->leftJoin('tree.referenceSecondaryPerson', 'referenceSecondary')
            ->leftJoin(
                Person::class,
                'person',
                'ON',
                'person.tree = tree',
            )
            ->andWhere('tree.owner = :owner')
            ->setParameter('owner', $owner)
            ->groupBy('tree.id, referencePrimary.id, referenceSecondary.id')
            ->orderBy('tree.updatedAt', 'DESC')
            ->addOrderBy('tree.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $trees;
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

        return 0 < (int)$builder->getQuery()->getSingleScalarResult();
    }

    public function countAll(): int
    {
        return (int)$this->createQueryBuilder('tree')
            ->select('COUNT(tree.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<array{id: string, name: string}> */
    public function searchForOwner(User $owner, string $query): array
    {
        $query = trim($query);
        $id = Uuid::isValid($query) ? $query : null;
        $query = $this->normalizer->normalize($query);
        if ('' === $query || mb_strlen($query) > 100) {
            throw new ApiException(422, 'TREE_SEARCH_QUERY_INVALID');
        }
        $maximumDistance = $this->normalizer->maximumDistance($query);
        $name = "TRIM(REGEXP_REPLACE_TEXT(LOWER(TRANSLATE_TEXT(tree.name, 'Ёё', 'Ее')), '[^[:alnum:]]+', ' ', 'g'))";
        $distance = 'LEVENSHTEIN_LESS_EQUAL(:query, ' . $name . ', :maximumDistance)';
        $similarity = 'STRICT_WORD_SIMILARITY(:query, ' . $name . ')';
        $builder = $this->createQueryBuilder('tree')
            ->select('tree.id', 'tree.name')
            ->where('tree.owner = :owner')
            ->setParameter('owner', $owner)
            ->setMaxResults(5);
        if (null !== $id) {
            $builder
                ->andWhere('tree.id = :id')
                ->setParameter('id', $id);
        } else {
            $builder
                ->addSelect('CASE WHEN ' . $name . ' = :query THEN 0 ELSE 1 END AS HIDDEN exactMatch')
                ->addSelect($distance . ' AS HIDDEN distance')
                ->addSelect($similarity . ' AS HIDDEN similarity')
                ->andWhere(
                    '(' . $distance . ' <= :maximumDistance OR (STRICT_WORD_MATCH(:query, ' . $name . ') = true AND '
                        . $similarity
                        . ' >= :minimumSimilarity))',
                )
                ->setParameter(
                    'query',
                    $query,
                )
                ->setParameter(
                    'maximumDistance',
                    $maximumDistance,
                )
                ->setParameter(
                    'minimumSimilarity',
                    PersonRepository::MINIMUM_STRICT_WORD_SIMILARITY,
                )
                ->orderBy(
                    'exactMatch',
                    'ASC',
                )
                ->addOrderBy(
                    'distance',
                    'ASC',
                )
                ->addOrderBy(
                    'similarity',
                    'DESC',
                );
        }
        /** @var list<array{id: Uuid, name: string}> $rows */
        $rows = $builder
            ->addOrderBy('tree.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => ['id' => (string)$row['id'], 'name' => $row['name']], $rows);
    }
}
