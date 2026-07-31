<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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
        return $this->createQueryBuilder('user')
            ->where('user.username = :identifier')
            ->orWhere('user.email = :normalizedEmail')
            ->setParameter('identifier', $identifier)
            ->setParameter('normalizedEmail', mb_strtolower($identifier))
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function usernameExists(string $username): bool
    {
        return null !== $this->findOneBy(['username' => $username]);
    }

    public function emailExists(string $email): bool
    {
        return null !== $this->findOneBy(['email' => $email]);
    }
}
