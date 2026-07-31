<?php

namespace App\Repository;

use App\Entity\RefreshToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RefreshToken>
 */
final class RefreshTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RefreshToken::class);
    }

    public function findOneByPlainToken(string $token): ?RefreshToken
    {
        return $this->queryByPlainToken($token)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByPlainTokenForUpdate(string $token): ?RefreshToken
    {
        return $this->queryByPlainToken($token)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    private function queryByPlainToken(string $token): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('token')
            ->addSelect('session', 'user')
            ->innerJoin('token.session', 'session')
            ->innerJoin('session.user', 'user')
            ->where('token.tokenHash = :hash')
            ->setParameter('hash', hash('sha256', $token));
    }
}
