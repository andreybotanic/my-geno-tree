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
    public const float MINIMUM_STRICT_WORD_SIMILARITY = 0.2;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Person::class);
    }

    /**
     * @return list<Person>
     */
    public function findAllForTree(FamilyTree $tree): array
    {
        /** @var list<Person> $people */
        $people = $this->createQueryBuilder('person')
            ->addSelect('photoAttachment', 'photoMedia', 'photoVariant', 'birthPlace', 'deathPlace')
            ->leftJoin('person.photoAttachment', 'photoAttachment')
            ->leftJoin('photoAttachment.media', 'photoMedia')
            ->leftJoin('photoMedia.variants', 'photoVariant')
            ->leftJoin('person.birthPlace', 'birthPlace')
            ->leftJoin('person.deathPlace', 'deathPlace')
            ->andWhere('person.tree = :tree')
            ->setParameter('tree', $tree)
            ->orderBy('person.createdAt', 'ASC')
            ->addOrderBy('person.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $people;
    }

    public function findInTree(FamilyTree $tree, string $id): ?Person
    {
        /** @var Person|null $person */
        $person = $this->createQueryBuilder('person')
            ->addSelect('photoAttachment', 'photoMedia', 'photoVariant', 'birthPlace', 'deathPlace')
            ->leftJoin('person.photoAttachment', 'photoAttachment')
            ->leftJoin('photoAttachment.media', 'photoMedia')
            ->leftJoin('photoMedia.variants', 'photoVariant')
            ->leftJoin('person.birthPlace', 'birthPlace')
            ->leftJoin('person.deathPlace', 'deathPlace')
            ->andWhere('person.tree = :tree')
            ->andWhere('person.id = :id')
            ->setParameter('tree', $tree)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        return $person;
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

    /**
     * @return list<Person>
     */
    public function findFuzzyNameMatches(
        FamilyTree $tree,
        string $query,
        int $maximumDistance,
        float $minimumSimilarity,
    ): array {
        $fullName = $this->normalizedFullNameExpression();
        $birthSurname = $this->normalizedTextExpression('person.birthSurname.value');
        $givenName = $this->normalizedTextExpression('person.givenName.value');
        $patronymic = $this->normalizedTextExpression('person.patronymic.value');
        $distance = sprintf(
            'LEAST_INTEGER(
                LEVENSHTEIN_LESS_EQUAL(:query, %s, :maximumDistance),
                LEVENSHTEIN_LESS_EQUAL(:query, %s, :maximumDistance),
                LEVENSHTEIN_LESS_EQUAL(:query, %s, :maximumDistance),
                LEVENSHTEIN_LESS_EQUAL(:query, CONCAT(%s, \' \', %s), :maximumDistance),
                LEVENSHTEIN_LESS_EQUAL(:query, CONCAT(%s, \' \', %s), :maximumDistance),
                LEVENSHTEIN_LESS_EQUAL(:query, SUBSTRING(%s, 1, 255), :maximumDistance)
            )',
            $birthSurname,
            $givenName,
            $patronymic,
            $birthSurname,
            $givenName,
            $givenName,
            $patronymic,
            $fullName,
        );
        $similarity = sprintf('STRICT_WORD_SIMILARITY(:query, %s)', $fullName);

        /** @var list<Person> $people */
        $people = $this->createQueryBuilder('person')
            ->addSelect($distance . ' AS HIDDEN distance')
            ->addSelect($similarity . ' AS HIDDEN similarity')
            ->andWhere('person.tree = :tree')
            ->andWhere(sprintf('STRICT_WORD_MATCH(:query, %s) = true', $fullName))
            ->andWhere($similarity . ' >= :minimumSimilarity')
            ->andWhere($distance . ' <= :maximumDistance')
            ->setParameter('tree', $tree)
            ->setParameter('query', $query)
            ->setParameter('maximumDistance', $maximumDistance)
            ->setParameter('minimumSimilarity', $minimumSimilarity)
            ->orderBy('distance', 'ASC')
            ->addOrderBy('similarity', 'DESC')
            ->addOrderBy('person.id', 'ASC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        return $people;
    }

    private function normalizedFullNameExpression(): string
    {
        return $this->normalizedTextExpression(
            "CONCAT(CONCAT(CONCAT(CONCAT(COALESCE(person.birthSurname.value, ''), ' '), COALESCE(person.givenName.value, '')), ' '), COALESCE(person.patronymic.value, ''))",
        );
    }

    private function normalizedTextExpression(string $value): string
    {
        return sprintf(
            "TRIM(REGEXP_REPLACE_TEXT(LOWER(TRANSLATE_TEXT(COALESCE(%s, ''), 'Ёё', 'Ее')), '[^[:alnum:]]+', ' ', 'g'))",
            $value,
        );
    }
}
