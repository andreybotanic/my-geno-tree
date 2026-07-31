<?php

namespace App\Application\Tree;

use App\Api\ApiException;
use App\Application\Event\AutomaticEventSynchronizer;
use App\Application\Person\PersonDataPolicy;
use App\Entity\FamilyTree;
use App\Entity\MarriagePeriod;
use App\Repository\MarriagePeriodRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Uid\Uuid;

final readonly class UpdateMarriagePeriodService
{
    public function __construct(
        private MarriagePeriodRepository $marriages,
        private PersonDataPolicy $personData,
        private GraphInvariantPolicy $invariants,
        private TreeVersion $versions,
        private AutomaticEventSynchronizer $events,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(
        FamilyTree $tree,
        string $marriageId,
        array $payload,
        ?string $ifMatch,
    ): MarriagePeriod {
        if ([] === $payload) {
            $this->invalid('marriage', 'at_least_one_field_required');
        }
        foreach (array_keys($payload) as $field) {
            if (!in_array($field, ['marriageDate', 'dissolutionDate'], true)) {
                $this->invalid($field, 'field_not_allowed');
            }
        }
        $marriage = Uuid::isValid($marriageId)
            ? $this->marriages->findInTree($tree, $marriageId)
            : null;
        if (null === $marriage) {
            throw new ApiException(
                JsonResponse::HTTP_NOT_FOUND,
                'NOT_FOUND',
                'not_found',
            );
        }

        return $this->entityManager->wrapInTransaction(function () use (
            $tree,
            $marriage,
            $payload,
            $ifMatch,
        ): MarriagePeriod {
            $this->entityManager->lock($tree, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($tree);
            $this->versions->assertMatches($tree, $ifMatch);
            $this->entityManager->lock(
                $marriage,
                LockMode::PESSIMISTIC_WRITE,
            );
            $this->entityManager->refresh($marriage);

            $marriageDate = array_key_exists('marriageDate', $payload)
                ? $this->personData->dateValue(
                    $payload['marriageDate'],
                    'marriageDate',
                )
                : $marriage->getMarriageDate();
            $dissolutionDate = array_key_exists('dissolutionDate', $payload)
                ? $this->personData->optionalDateValue(
                    $payload['dissolutionDate'],
                    'dissolutionDate',
                )
                : $marriage->getDissolutionDate();
            $this->invariants->assertMarriageDates(
                $marriage->getMalePerson(),
                $marriage->getFemalePerson(),
                $marriageDate,
                $dissolutionDate,
            );
            $changedAt = $this->clock->now();
            $marriage->changeDates(
                $marriageDate,
                $dissolutionDate,
                $changedAt,
            );
            $this->events->synchronizeMarriageDates($marriage, $changedAt);
            $tree->touch($changedAt);

            return $marriage;
        });
    }

    private function invalid(string $field, string $error): never
    {
        throw new ApiException(
            JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            'MARRIAGE_DATA_INVALID',
            'marriage_data_invalid',
            [$field => [$error]],
        );
    }
}
