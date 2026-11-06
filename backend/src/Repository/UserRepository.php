<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByLoginIdentifier(string $identifier): ?User
    {
        /** @var User|null $user */
        $user = $this->createQueryBuilder('user')
            ->where('user.username = :identifier')
            ->orWhere('user.email = :normalizedEmail')
            ->setParameter('identifier', $identifier)
            ->setParameter('normalizedEmail', mb_strtolower($identifier))
            ->getQuery()
            ->getOneOrNullResult();

        return $user;
    }

    public function usernameExists(string $username): bool
    {
        return null !== $this->findOneBy(['username' => $username]);
    }

    public function withChatBan(Uuid $id): ?User
    {
        /** @var User|null $user */
        $user = $this->createQueryBuilder('user')
            ->leftJoin('user.lastChatBan', 'ban')
            ->addSelect('ban')
            ->leftJoin('user.avatarAttachment', 'avatar')
            ->addSelect('avatar')
            ->where('user.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $user;
    }

    public function emailExists(string $email): bool
    {
        return null !== $this->findOneBy(['email' => $email]);
    }

    public function countAll(): int
    {
        return (int)$this->createQueryBuilder('user')
            ->select('COUNT(user.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
