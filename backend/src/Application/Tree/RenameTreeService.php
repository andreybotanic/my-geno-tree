<?php

namespace App\Application\Tree;

use App\Api\ApiException;
use App\Entity\FamilyTree;
use App\Repository\FamilyTreeRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class RenameTreeService
{
    public function __construct(
        private TreeNamePolicy $names,
        private TreeVersion $versions,
        private FamilyTreeRepository $trees,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function rename(
        FamilyTree $tree,
        string $requestedName,
        ?string $ifMatch,
    ): void {
        $name = $this->names->normalize($requestedName);

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $tree,
                $name,
                $ifMatch,
            ): void {
                $this->entityManager->lock($tree, LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->refresh($tree);
                $this->versions->assertMatches($tree, $ifMatch);

                if ($this->trees->nameExistsForOwner(
                    $tree->getOwner(),
                    $name,
                    $tree,
                )) {
                    throw $this->nameConflict();
                }

                $tree->rename($name, $this->clock->now());
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->nameConflict($exception);
        }
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
