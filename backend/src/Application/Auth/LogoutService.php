<?php

namespace App\Application\Auth;

use App\Entity\AuthSession;
use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class LogoutService
{
    public function __construct(
        private RefreshTokenRepository $refreshTokens,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function logout(?AuthSession $session, ?string $plainRefreshToken): void
    {
        if (null === $session && null !== $plainRefreshToken) {
            $session = $this->refreshTokens
                ->findOneByPlainToken($plainRefreshToken)
                ?->getSession();
        }
        if (null === $session) {
            return;
        }

        $session->revoke($this->clock->now());
        $this->entityManager->flush();
    }
}
