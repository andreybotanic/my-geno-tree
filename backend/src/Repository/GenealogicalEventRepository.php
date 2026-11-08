<?php

namespace App\Repository;

use App\Entity\FamilyTree;
use App\Entity\GenealogicalEvent;
use App\Entity\Person;
use App\Entity\Value\EventSource;
use App\Entity\Value\EventType;
use App\Infrastructure\Doctrine\Hydration\GenealogyRecordHydrator;
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

    /** @param list<string>|null $personIds
     * @param list<string>|null $types
     *
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    public function readPage(
        FamilyTree $tree,
        ?array $personIds,
        ?array $types,
        ?string $source,
        ?string $after,
        int $limit,
    ): array {
        if ([] === $personIds || [] === $types) {
            return ['total' => 0, 'items' => []];
        }
        $query = $this
            ->createQueryBuilder('item')
            ->join('item.person', 'owner')
            ->addSelect('IDENTITY(item.person) AS personId', 'place')
            ->leftJoin('item.place', 'place')
            ->andWhere('owner.tree = :tree')
            ->setParameter('tree', $tree);
        if (null !== $personIds) {
            $query
                ->andWhere('item.person IN (:people)')
                ->setParameter('people', $personIds);
        }
        if (null !== $types) {
            $query
                ->andWhere('item.type IN (:types)')
                ->setParameter('types', $types);
        }
        if (null !== $source) {
            $query
                ->andWhere('item.source = :source')
                ->setParameter('source', $source);
        }

        $total = (int)(clone $query)
            ->select('COUNT(item.id)')
            ->getQuery()
            ->getSingleScalarResult();
        if (null !== $after) {
            $query
                ->andWhere('item.id > :after')
                ->setParameter('after', $after);
        }
        /** @var list<array<string, mixed>> $items */
        $items = $query
            ->orderBy('item.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult(GenealogyRecordHydrator::MODE);

        return ['total' => $total, 'items' => $items];
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

    public function findInTree(FamilyTree $tree, string $id): ?GenealogicalEvent
    {
        /** @var GenealogicalEvent|null $event */
        $event = $this->createQueryBuilder('event')
            ->addSelect('person', 'place')
            ->join('event.person', 'person')
            ->leftJoin('event.place', 'place')
            ->andWhere('person.tree = :tree')
            ->andWhere('event.id = :id')
            ->setParameter('tree', $tree)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        return $event;
    }

    /** @return list<GenealogicalEvent> */
    public function findAutomaticGroup(EventType $type, Uuid $sourceEntityId): array
    {
        /** @var list<GenealogicalEvent> $events */
        $events = $this->createQueryBuilder('event')
            ->addSelect('place')
            ->leftJoin('event.place', 'place')
            ->andWhere('event.type = :type')
            ->andWhere('event.source = :source')
            ->andWhere('event.sourceEntityId = :sourceEntityId')
            ->setParameter('type', $type)
            ->setParameter('source', EventSource::AUTOMATIC)
            ->setParameter('sourceEntityId', $sourceEntityId)
            ->getQuery()
            ->getResult();

        return $events;
    }

    /**
     * @return list<GenealogicalEvent>
     */
    public function findAllForSource(Uuid $sourceEntityId): array
    {
        /** @var list<GenealogicalEvent> $events */
        $events = $this->createQueryBuilder('event')
            ->addSelect('place')
            ->leftJoin('event.place', 'place')
            ->andWhere('event.sourceEntityId = :sourceEntityId')
            ->setParameter('sourceEntityId', $sourceEntityId)
            ->getQuery()
            ->getResult();

        return $events;
    }

    /**
     * @return list<GenealogicalEvent>
     */
    public function findTimeline(Person $person): array
    {
        /** @var list<GenealogicalEvent> $events */
        $events = $this->createQueryBuilder('event')
            ->addSelect('place')
            ->leftJoin('event.place', 'place')
            ->andWhere('event.person = :person')
            ->setParameter('person', $person)
            ->orderBy('event.createdAt', 'ASC')
            ->addOrderBy('event.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $events;
    }

    /** @return list<GenealogicalEvent> */
    public function findAllForTree(FamilyTree $tree): array
    {
        /** @var list<GenealogicalEvent> $events */
        $events = $this->createQueryBuilder('event')
            ->addSelect('person')
            ->addSelect('place')
            ->join('event.person', 'person')
            ->leftJoin('event.place', 'place')
            ->andWhere('person.tree = :tree')
            ->setParameter('tree', $tree)
            ->orderBy('person.createdAt', 'ASC')
            ->addOrderBy('event.createdAt', 'ASC')
            ->addOrderBy('event.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $events;
    }
}
