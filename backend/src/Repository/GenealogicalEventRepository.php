<?php

namespace App\Repository;

use App\Entity\GenealogicalEvent;
use App\Entity\Person;
use App\Entity\Value\EventSource;
use App\Entity\Value\EventType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<GenealogicalEvent>
 */
final class GenealogicalEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GenealogicalEvent::class);
    }

    public function findAutomaticPersonLifecycle(
        Person $person,
        EventType $type,
    ): ?GenealogicalEvent {
        return $this->findOneBy([
            'person'         => $person,
            'type'           => $type,
            'sourceEntityId' => $person->getId(),
        ]);
    }

    public function findAutomaticForSource(
        Person $person,
        EventType $type,
        Uuid $sourceEntityId,
    ): ?GenealogicalEvent {
        return $this->findOneBy([
            'person'         => $person,
            'type'           => $type,
            'sourceEntityId' => $sourceEntityId,
        ]);
    }

    public function findManualForPerson(
        Person $person,
        string $id,
    ): ?GenealogicalEvent {
        return $this->findOneBy([
            'id'     => $id,
            'person' => $person,
            'source' => EventSource::MANUAL,
        ]);
    }

    /**
     * @return list<GenealogicalEvent>
     */
    public function findAllForSource(Uuid $sourceEntityId): array
    {
        return $this->findBy(['sourceEntityId' => $sourceEntityId]);
    }

    /**
     * @return list<GenealogicalEvent>
     */
    public function findTimeline(Person $person): array
    {
        /** @var list<GenealogicalEvent> $events */
        $events = $this->createQueryBuilder('event')
            ->andWhere('event.person = :person')
            ->setParameter('person', $person)
            ->orderBy('event.createdAt', 'ASC')
            ->addOrderBy('event.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $events;
    }
}
