<?php

namespace App\Application\Event;

use App\Entity\GenealogicalEvent;
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
}
