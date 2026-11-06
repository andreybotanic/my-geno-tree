<?php

namespace App\Application\Person;

use App\Api\ApiException;
use App\Application\Event\AutomaticEventSynchronizer;
use App\Application\Tree\TreeVersion;
use App\Entity\EventPlace;
use App\Entity\FamilyTree;
use App\Entity\Person;
use App\Repository\PersonRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class CreateFirstPersonService
{
    public function __construct(
        private PersonDataPolicy $dataPolicy,
        private PersonRepository $people,
        private TreeVersion $versions,
        private AutomaticEventSynchronizer $events,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(
        FamilyTree $tree,
        array $payload,
        ?string $ifMatch,
    ): Person {
        $this->assertShape($payload);
        $personData = $this->dataPolicy->create($payload['person']);

        return $this->entityManager->wrapInTransaction(
            function () use (
                $tree,
                $personData,
                $ifMatch,
            ): Person {
                $this->entityManager->lock($tree, LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->refresh($tree);
                $this->versions->assertMatches($tree, $ifMatch);
                if (0 !== $this->people->countForTree($tree)) {
                    throw new ApiException(JsonResponse::HTTP_CONFLICT, 'FIRST_PERSON_ALREADY_EXISTS', 'first_person_already_exists');
                }

                $changedAt = $this->clock->now();
                $person = new Person(
                    $tree,
                    $personData->birthSurname,
                    $personData->givenName,
                    $personData->patronymic,
                    $personData->gender,
                    $personData->birthDate,
                    $personData->deathDate,
                    $changedAt,
                );
                $this->entityManager->persist($person);
                if (null !== $personData->birthPlace) {
                    $place = EventPlace::resolved(
                        $personData->birthPlace->name,
                        $personData->birthPlace->latitude,
                        $personData->birthPlace->longitude,
                    );
                    $this->entityManager->persist($place);
                    $person->changeBirthPlace($place, $changedAt);
                }
                if (null !== $personData->deathPlace) {
                    $place = EventPlace::resolved(
                        $personData->deathPlace->name,
                        $personData->deathPlace->latitude,
                        $personData->deathPlace->longitude,
                    );
                    $this->entityManager->persist($place);
                    $person->changeDeathPlace($place, $changedAt);
                }
                $this->events->synchronizePersonLifecycle($person, $changedAt);
                $tree->referencePerson($person);
                $tree->touch($changedAt);

                return $person;
            },
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertShape(array $payload): void
    {
        $expected = ['type', 'person'];
        foreach (array_keys($payload) as $key) {
            if (!in_array($key, $expected, true)) {
                $this->invalid($key, 'field_not_allowed');
            }
        }
        foreach ($expected as $key) {
            if (!array_key_exists($key, $payload)) {
                $this->invalid($key, 'field_required');
            }
        }
        if ('create_first_person' !== $payload['type']) {
            $this->invalid('type', 'graph_mutation_type_invalid');
        }
    }

    private function invalid(string $field, string $error): never
    {
        throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'GRAPH_MUTATION_INVALID', 'graph_mutation_invalid', [$field => [$error]]);
    }
}
