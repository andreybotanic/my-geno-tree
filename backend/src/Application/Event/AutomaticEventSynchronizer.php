<?php

namespace App\Application\Event;

use App\Entity\GenealogicalEvent;
use App\Entity\MarriagePeriod;
use App\Entity\ParentLink;
use App\Entity\Person;
use App\Entity\Value\EventType;
use App\Entity\Value\GenealogicalDate;
use App\Repository\GenealogicalEventRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AutomaticEventSynchronizer
{
    public function __construct(
        private GenealogicalEventRepository $events,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function synchronizePersonLifecycle(
        Person $person,
        DateTimeImmutable $changedAt,
    ): void {
        $this->upsert(
            $person,
            EventType::BIRTH,
            $person->getBirthDate(),
            $changedAt,
        );

        $death = $this->events->findAutomaticPersonLifecycle(
            $person,
            EventType::DEATH,
        );
        $deathDate = $person->getDeathDate();
        if (null === $deathDate) {
            if (null !== $death) {
                $this->entityManager->remove($death);
            }

            return;
        }

        if (null === $death) {
            $this->entityManager->persist(
                GenealogicalEvent::automaticPersonLifecycle(
                    $person,
                    EventType::DEATH,
                    $deathDate,
                    $changedAt,
                ),
            );

            return;
        }

        $death->changeAutomaticDate($deathDate, $changedAt);
    }

    public function synchronizeParentLink(
        ParentLink $link,
        DateTimeImmutable $changedAt,
    ): void {
        $this->upsertRelated(
            $link->getParent(),
            EventType::CHILD_BIRTH,
            $link->getChild()->getBirthDate(),
            'parent_link',
            $link->getId(),
            ['childId' => $link->getChild()->getId()->toRfc4122()],
            $changedAt,
        );
    }

    public function synchronizeMarriage(
        MarriagePeriod $marriage,
        ?string $maleNewSurname,
        ?string $femaleNewSurname,
        DateTimeImmutable $changedAt,
    ): void {
        $this->synchronizeMarriageDates($marriage, $changedAt);
        $this->synchronizeMarriageSurname(
            $marriage->getMalePerson(),
            $marriage,
            $maleNewSurname,
            $changedAt,
        );
        $this->synchronizeMarriageSurname(
            $marriage->getFemalePerson(),
            $marriage,
            $femaleNewSurname,
            $changedAt,
        );
    }

    public function synchronizeMarriageDates(
        MarriagePeriod $marriage,
        DateTimeImmutable $changedAt,
    ): void {
        $people = [
            [$marriage->getMalePerson(), $marriage->getFemalePerson()],
            [$marriage->getFemalePerson(), $marriage->getMalePerson()],
        ];
        foreach ($people as [$person, $partner]) {
            $this->upsertRelated(
                $person,
                EventType::MARRIAGE,
                $marriage->getMarriageDate(),
                'marriage_period',
                $marriage->getId(),
                ['partnerId' => $partner->getId()->toRfc4122()],
                $changedAt,
            );
        }

        $dissolutionDate = $marriage->getDissolutionDate();
        foreach ($people as [$person, $partner]) {
            if (null === $dissolutionDate) {
                $this->removeRelated(
                    $person,
                    EventType::DIVORCE,
                    $marriage->getId(),
                );
            } else {
                $this->upsertRelated(
                    $person,
                    EventType::DIVORCE,
                    $dissolutionDate,
                    'marriage_period',
                    $marriage->getId(),
                    ['partnerId' => $partner->getId()->toRfc4122()],
                    $changedAt,
                );
            }
        }
    }

    public function removeForSource(
        \Symfony\Component\Uid\Uuid $sourceEntityId,
    ): void {
        foreach ($this->events->findAllForSource($sourceEntityId) as $event) {
            $this->entityManager->remove($event);
        }
    }

    private function upsert(
        Person $person,
        EventType $type,
        GenealogicalDate $date,
        DateTimeImmutable $changedAt,
    ): void {
        $event = $this->events->findAutomaticPersonLifecycle($person, $type);
        if (null === $event) {
            $this->entityManager->persist(
                GenealogicalEvent::automaticPersonLifecycle(
                    $person,
                    $type,
                    $date,
                    $changedAt,
                ),
            );

            return;
        }

        $event->changeAutomaticDate($date, $changedAt);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function upsertRelated(
        Person $person,
        EventType $type,
        GenealogicalDate $date,
        string $sourceEntityType,
        \Symfony\Component\Uid\Uuid $sourceEntityId,
        array $data,
        DateTimeImmutable $changedAt,
    ): void {
        $event = $this->events->findAutomaticForSource(
            $person,
            $type,
            $sourceEntityId,
        );
        if (null === $event) {
            $this->entityManager->persist(
                GenealogicalEvent::automaticRelated(
                    $person,
                    $type,
                    $date,
                    $sourceEntityType,
                    $sourceEntityId,
                    $data,
                    $changedAt,
                ),
            );

            return;
        }

        $event->changeAutomatic($date, $data, $changedAt);
    }

    private function removeRelated(
        Person $person,
        EventType $type,
        \Symfony\Component\Uid\Uuid $sourceEntityId,
    ): void {
        $event = $this->events->findAutomaticForSource(
            $person,
            $type,
            $sourceEntityId,
        );
        if (null !== $event) {
            $this->entityManager->remove($event);
        }
    }

    private function synchronizeMarriageSurname(
        Person $person,
        MarriagePeriod $marriage,
        ?string $newSurname,
        DateTimeImmutable $changedAt,
    ): void {
        if (null === $newSurname) {
            $this->removeRelated(
                $person,
                EventType::SURNAME_CHANGE,
                $marriage->getId(),
            );

            return;
        }

        $this->upsertRelated(
            $person,
            EventType::SURNAME_CHANGE,
            $marriage->getMarriageDate(),
            'marriage_period',
            $marriage->getId(),
            ['newSurname' => $newSurname],
            $changedAt,
        );
    }
}
