<?php

namespace App\Application\Tree;

use App\Api\ApiException;
use App\Entity\FamilyTree;
use App\Entity\User;
use App\Repository\FamilyTreeRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class CreateTreeService
{
    public function __construct(
        private TreeNamePolicy $names,
        private FamilyTreeRepository $trees,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function create(User $owner, string $requestedName): FamilyTree
    {
        $name = $this->names->normalize($requestedName);
        if ($this->trees->nameExistsForOwner($owner, $name)) {
            throw $this->nameConflict();
        }

        $tree = new FamilyTree($owner, $name, $this->clock->now());
        try {
            $this->entityManager->wrapInTransaction(function () use ($tree): void {
                $this->entityManager->persist($tree);
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->nameConflict($exception);
        }

        return $tree;
    }

    private function nameConflict(
        ?UniqueConstraintViolationException $previous = null,
    ): ApiException {
        return new ApiException(
            JsonResponse::HTTP_CONFLICT,
            'TREE_NAME_CONFLICT',
            'tree_name_conflict',
            ['name' => ['tree_name_not_unique']],
            previous: $previous,
        );
    }
}
