<?php

namespace App\Application\Person;

use App\Api\ApiException;
use App\Entity\FamilyTree;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class PersonSearchService
{
    public function __construct(
        private Connection $connection,
        private PersonNameNormalizer $normalizer,
    ) {
    }

    /**
     * @return list<array{id: string, fullName: string, birthYear: int|null, deathYear: int|null}>
     */
    public function search(FamilyTree $tree, string $query): array
    {
        $query = $this->normalizer->normalize($query);
        if (mb_strlen($query, 'UTF-8') < 2 || mb_strlen($query, 'UTF-8') > 255) {
            throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'PERSON_SEARCH_QUERY_INVALID', 'person_search_query_invalid', ['q' => ['length_must_be_between_2_and_255']]);
        }

        $distance = $this->normalizer->maximumDistance($query);
        $name = $this->normalizedNameExpression();
        $birthSurname = $this->normalizedValueExpression('person.birth_surname_value');
        $givenName = $this->normalizedValueExpression('person.given_name_value');
        $patronymic = $this->normalizedValueExpression('person.patronymic_value');
        $sql = <<<SQL
            WITH candidates AS (
                SELECT
                    person.id::text AS id,
                    person.birth_surname_value,
                    person.given_name_value,
                    person.patronymic_value,
                    person.birth_date_year AS birth_year,
                    person.death_date_year AS death_year,
                    strict_word_similarity(:query, {$name}) AS similarity,
                    LEAST(
                        levenshtein_less_equal(:query, {$birthSurname}, :distance),
                        levenshtein_less_equal(:query, {$givenName}, :distance),
                        levenshtein_less_equal(:query, {$patronymic}, :distance),
                        levenshtein_less_equal(:query, trim({$birthSurname} || ' ' || {$givenName}), :distance),
                        levenshtein_less_equal(:query, trim({$givenName} || ' ' || {$patronymic}), :distance),
                        levenshtein_less_equal(:query, left({$name}, 255), :distance)
                    ) AS distance
                FROM person
                WHERE person.tree_id = :treeId
                  AND :query <<% {$name}
            )
            SELECT id, birth_surname_value, given_name_value, patronymic_value, birth_year, death_year
            FROM candidates
            WHERE distance <= :distance
            ORDER BY distance ASC, similarity DESC, id ASC
            LIMIT 5
            SQL;

        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement(
                'SET LOCAL pg_trgm.strict_word_similarity_threshold = 0.2',
            );
            /** @var list<array<string, int|string|null>> $rows */
            $rows = $this->connection->fetchAllAssociative($sql, [
                'query'    => $query,
                'distance' => $distance,
                'treeId'   => $tree->getId()->toRfc4122(),
            ]);
            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }

        return array_map(static function (array $row): array {
            $name = trim(implode(' ', array_filter([
                $row['birth_surname_value'],
                $row['given_name_value'],
                $row['patronymic_value'],
            ], static fn (int|string|null $value): bool => null !== $value && '' !== $value)));

            return [
                'id'        => (string)$row['id'],
                'fullName'  => '' === $name ? '—' : $name,
                'birthYear' => null === $row['birth_year'] ? null : (int)$row['birth_year'],
                'deathYear' => null === $row['death_year'] ? null : (int)$row['death_year'],
            ];
        }, $rows);
    }

    private function normalizedNameExpression(): string
    {
        return "trim(regexp_replace(lower(translate(COALESCE(person.birth_surname_value, '') || ' ' || COALESCE(person.given_name_value, '') || ' ' || COALESCE(person.patronymic_value, ''), 'Ёё', 'Ее')), '[^[:alnum:]]+', ' ', 'g'))";
    }

    private function normalizedValueExpression(string $column): string
    {
        return "trim(regexp_replace(lower(translate(COALESCE({$column}, ''), 'Ёё', 'Ее')), '[^[:alnum:]]+', ' ', 'g'))";
    }
}
