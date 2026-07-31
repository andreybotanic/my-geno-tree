<?php

namespace App\Application\Person;

use App\Api\ApiException;
use App\Entity\FamilyTree;
use App\Entity\Person;
use App\Repository\PersonRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Uid\Uuid;

final readonly class PersonLookup
{
    public function __construct(private PersonRepository $people)
    {
    }

    public function require(FamilyTree $tree, string $personId): Person
    {
        $person = Uuid::isValid($personId)
            ? $this->people->findInTree($tree, $personId)
            : null;
        if (null === $person) {
            throw new ApiException(JsonResponse::HTTP_NOT_FOUND, 'NOT_FOUND', 'not_found');
        }

        return $person;
    }
}
