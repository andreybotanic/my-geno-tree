<?php

namespace App\Application\Person;

use App\Api\ApiException;
use App\Entity\FamilyTree;
use App\Entity\Person;
use App\Repository\PersonRepository;
use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class PersonSearchService
{
    public function __construct(
        private PersonRepository $people,
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

        $people = $this->people->findFuzzyNameMatches(
            $tree,
            $query,
            $this->normalizer->maximumDistance($query),
            PersonRepository::MINIMUM_STRICT_WORD_SIMILARITY,
        );

        return array_map(static function (Person $person): array {
            $name = trim(implode(' ', array_filter([
                $person->getBirthSurname()->getValue(),
                $person->getGivenName()->getValue(),
                $person->getPatronymic()->getValue(),
            ], static fn (?string $value): bool => null !== $value && '' !== $value)));

            return [
                'id'        => $person->getId()->toRfc4122(),
                'fullName'  => '' === $name ? '—' : $name,
                'birthYear' => $person->getBirthDate()->getYear(),
                'deathYear' => $person->getDeathDate()?->getYear(),
            ];
        }, $people);
    }
}
