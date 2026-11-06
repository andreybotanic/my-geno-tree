<?php

namespace App\Repository;

use App\Entity\SuspiciousChatMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SuspiciousChatMessage> */
final class SuspiciousChatMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SuspiciousChatMessage::class);
    }

    public function findOperation(string $key): ?SuspiciousChatMessage
    {
        /** @var SuspiciousChatMessage|null $message */
        $message = $this->createQueryBuilder('message')
            ->where('message.operationKey = :key')
            ->setParameter('key', $key)
            ->getQuery()
            ->getOneOrNullResult();

        return $message;
    }

    public function purgeBefore(\DateTimeImmutable $cutoff): int
    {
        /** @var int $deleted */
        $deleted = $this->createQueryBuilder('message')
            ->delete()
            ->where('message.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();

        return $deleted;
    }
}
