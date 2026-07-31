<?php

namespace App\Application\Person;

use App\Application\Event\AutomaticEventSynchronizer;
use App\Application\Tree\GraphInvariantPolicy;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Entity\Person;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class UpdatePersonService
{
    public function __construct(
        private PersonDataPolicy $dataPolicy,
        private GraphInvariantPolicy $graphInvariants,
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
        Person $person,
        array $payload,
        ?string $ifMatch,
    ): void {
        $this->entityManager->wrapInTransaction(function () use (
            $tree,
            $person,
            $payload,
            $ifMatch,
        ): void {
            $this->entityManager->lock($tree, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($tree);
            $this->versions->assertMatches($tree, $ifMatch);
            $this->entityManager->lock($person, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($person);

            $data = $this->dataPolicy->patch($person, $payload);
            $this->graphInvariants->assertPersonDataChangeAllowed(
                $person,
                $data,
            );
            $changedAt = $this->clock->now();
            $person->update(
                $data->birthSurname,
                $data->givenName,
                $data->patronymic,
                $data->gender,
                $data->birthDate,
                $data->deathDate,
                $changedAt,
            );
            $this->events->synchronizePersonLifecycle($person, $changedAt);
            $tree->touch($changedAt);
        });
    }
}
