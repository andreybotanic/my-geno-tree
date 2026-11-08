<?php

namespace App\Application\Person;

use App\Api\ApiException;
use App\Application\Event\AutomaticEventSynchronizer;
use App\Application\Event\ManualEventDataPolicy;
use App\Application\Event\SourcePlaceService;
use App\Application\Tree\GraphInvariantPolicy;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Entity\Person;
use App\Entity\Value\EventSource;
use App\Repository\GenealogicalEventRepository;
use App\Repository\ParentLinkRepository;
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
        private SourcePlaceService $places,
        private ManualEventDataPolicy $manualEvents,
        private GenealogicalEventRepository $eventRepository,
        private ParentLinkRepository $parentLinks,
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
        $this->entityManager->wrapInTransaction(
            function () use (
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

                $personal = array_diff_key($payload, ['birthPlace' => true, 'deathPlace' => true]);
                if ([] === $payload) {
                    throw new ApiException(422, 'PERSON_DATA_INVALID');
                }
                $data = $this->dataPolicy->patch($person, $personal ?: ['gender' => $person->getGender()->value]);
                if (null === $data->deathDate && null !== ($payload['deathPlace'] ?? null)) {
                    throw new ApiException(422, 'PERSON_DATA_INVALID', fields: ['deathPlace' => ['death_place_requires_death']]);
                }
                $this->graphInvariants->assertPersonDataChangeAllowed(
                    $person,
                    $data,
                );
                $changedAt = $this->clock->now();
                $timeline = $this->eventRepository->findTimeline($person);
                $removedDeathPlace = null === $data->deathDate
                    ? $person->getDeathPlace()
                    : null;
                $person->update(
                    $data->birthSurname,
                    $data->givenName,
                    $data->patronymic,
                    $data->gender,
                    $data->birthDate,
                    $data->deathDate,
                    $changedAt,
                );
                foreach (['birthPlace', 'deathPlace'] as $field) {
                    if (!array_key_exists($field, $payload)) {
                        continue;
                    }
                    $current = 'birthPlace' === $field ? $person->getBirthPlace() : $person->getDeathPlace();
                    $place = $this->places->update($current, $payload[$field], $field);
                    if ('birthPlace' === $field) {
                        $person->changeBirthPlace($place, $changedAt);
                    } else {
                        $person->changeDeathPlace($place, $changedAt);
                    }
                }
                $this->events->synchronizePersonLifecycle($person, $changedAt);
                foreach ($timeline as $event) {
                    if (EventSource::MANUAL === $event->getSource()) {
                        $this->manualEvents->assertWithinLife($person, $event->getDate());
                    }
                }
                foreach ($this->parentLinks->findForChildren($tree, [$person->getId()->toRfc4122()]) as $link) {
                    $this->events->synchronizeParentLink($link, $changedAt);
                }
                if (null !== $removedDeathPlace) {
                    $this->entityManager->remove($removedDeathPlace);
                }
                $tree->touch($changedAt);
            }
        );
    }
}
