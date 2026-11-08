<?php

namespace App\Application\Tree;

use App\Api\ApiException;
use App\Application\Event\AutomaticEventSynchronizer;
use App\Application\Event\SourcePlaceService;
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
        private SourcePlaceService $places,
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
            if (!in_array($field, ['marriageDate', 'dissolutionDate', 'marriagePlace', 'dissolutionPlace'], true)) {
                $this->invalid($field, 'field_not_allowed');
            }
        }
        $marriage = Uuid::isValid($marriageId)
            ? $this->marriages->findInTree($tree, $marriageId)
            : null;
        if (null === $marriage) {
            throw new ApiException(JsonResponse::HTTP_NOT_FOUND, 'NOT_FOUND', 'not_found');
        }

        return $this->entityManager->wrapInTransaction(
            function () use (
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
                if (null === $dissolutionDate && null !== ($payload['dissolutionPlace'] ?? null)) {
                    $this->invalid('dissolutionPlace', 'dissolution_place_requires_dissolution');
                }
                $removedDissolutionPlace = null === $dissolutionDate
                    ? $marriage->getDissolutionPlace()
                    : null;
                $marriage->changeDates(
                    $marriageDate,
                    $dissolutionDate,
                    $changedAt,
                );
                foreach (['marriagePlace', 'dissolutionPlace'] as $field) {
                    if (!array_key_exists($field, $payload)) {
                        continue;
                    }
                    $current = 'marriagePlace' === $field
                        ? $marriage->getMarriagePlace() : $marriage->getDissolutionPlace();
                    $place = $this->places->update($current, $payload[$field], $field);
                    if ('marriagePlace' === $field) {
                        $marriage->changeMarriagePlace($place, $changedAt);
                    } else {
                        $marriage->changeDissolutionPlace($place, $changedAt);
                    }
                }
                $this->events->synchronizeMarriageDates($marriage, $changedAt);
                if (null !== $removedDissolutionPlace) {
                    $this->entityManager->remove($removedDissolutionPlace);
                }
                $tree->touch($changedAt);

                return $marriage;
            }
        );
    }

    private function invalid(string $field, string $error): never
    {
        throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'MARRIAGE_DATA_INVALID', 'marriage_data_invalid', [$field => [$error]]);
    }
}
