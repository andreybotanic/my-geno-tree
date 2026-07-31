<?php

namespace App\Application\Auth;

use App\Api\ApiException;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Request\Auth\LoginRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

final readonly class LoginService
{
    private const string FALLBACK_PASSWORD_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

    public function __construct(
        private UserRepository $users,
        private PasswordHasherFactoryInterface $passwordHashers,
        private AuthenticationPolicy $authenticationPolicy,
        private SessionTokenFactory $tokens,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function login(LoginRequest $request): TokenBundle
    {
        $identifier = trim($request->identifier);
        $user = $this->users->findOneByLoginIdentifier($identifier);
        $passwordHasher = $this->passwordHashers->getPasswordHasher(User::class);
        $passwordIsValid = $passwordHasher->verify(
            $user?->getPassword() ?? self::FALLBACK_PASSWORD_HASH,
            $request->password,
        );

        if (null === $user || !$passwordIsValid) {
            throw new ApiException(
                JsonResponse::HTTP_UNAUTHORIZED,
                'INVALID_CREDENTIALS',
                'invalid_credentials',
            );
        }

        $this->authenticationPolicy->assertCanIssueTokens($user);
        $bundle = $this->tokens->create($user, $this->clock->now());
        $this->entityManager->wrapInTransaction(function () use ($bundle): void {
            $this->entityManager->persist($bundle->session);
            $this->entityManager->persist($bundle->refreshTokenEntity);
        });

        return $bundle;
    }
}
