<?php

namespace App\Repository;

use App\Entity\GenealogicalEvent;
use App\Entity\Person;
use App\Entity\Value\EventType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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
            'person' => $person,
            'type' => $type,
            'sourceEntityId' => $person->getId(),
        ]);
    }

    /**
     * @return list<GenealogicalEvent>
     */
    public function findTimeline(Person $person): array
    {
        return $this->createQueryBuilder('event')
            ->andWhere('event.person = :person')
            ->setParameter('person', $person)
            ->orderBy('event.createdAt', 'ASC')
            ->addOrderBy('event.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
