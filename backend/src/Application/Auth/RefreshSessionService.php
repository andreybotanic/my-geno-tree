<?php

namespace App\Application\Auth;

use App\Api\ApiException;
use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class RefreshSessionService
{
    public function __construct(
        private RefreshTokenRepository $refreshTokens,
        private SessionTokenFactory $tokens,
        private AuthenticationPolicy $authenticationPolicy,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function refresh(string $plainToken): TokenBundle
    {
        $now = $this->clock->now();

        /** @var TokenBundle|'invalid'|'reused' $result */
        $result = $this->entityManager->wrapInTransaction(function () use ($plainToken, $now): TokenBundle|string {
            $refreshToken = $this->refreshTokens->findOneByPlainTokenForUpdate($plainToken);
            if (null === $refreshToken) {
                return 'invalid';
            }

            $session = $refreshToken->getSession();
            if ($refreshToken->isUsed()) {
                $session->revoke($now);

                return 'reused';
            }
            if ($refreshToken->isExpired($now) || !$session->isRefreshActive($now)) {
                return 'invalid';
            }

            $this->authenticationPolicy->assertCanIssueTokens($session->getUser());
            $refreshToken->markUsed($now);
            $bundle = $this->tokens->rotate($session, $now);
            $this->entityManager->persist($bundle->refreshTokenEntity);

            return $bundle;
        });

        if (!$result instanceof TokenBundle) {
            throw new ApiException(JsonResponse::HTTP_UNAUTHORIZED, 'INVALID_REFRESH_TOKEN', 'invalid_refresh_token');
        }

        return $result;
    }
}
