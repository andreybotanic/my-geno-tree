<?php

namespace App\Repository;

use App\Entity\AuthSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuthSession>
 */
final class AuthSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuthSession::class);
    }

    public function findOneByAccessToken(string $token): ?AuthSession
    {
        return $this->createQueryBuilder('session')
            ->addSelect('user', 'avatarAttachment', 'avatarMedia', 'avatarVariant')
            ->innerJoin('session.user', 'user')
            ->leftJoin('user.avatarAttachment', 'avatarAttachment')
            ->leftJoin('avatarAttachment.media', 'avatarMedia')
            ->leftJoin('avatarMedia.variants', 'avatarVariant')
            ->where('session.accessTokenHash = :hash')
            ->setParameter('hash', hash('sha256', $token))
            ->getQuery()
            ->getOneOrNullResult();
    }
}
