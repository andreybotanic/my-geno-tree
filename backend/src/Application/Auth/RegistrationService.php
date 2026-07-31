<?php

namespace App\Application\Auth;

use App\Api\ApiException;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Request\Auth\RegisterRequest;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class RegistrationService
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private PasswordHasherFactoryInterface $passwordHashers,
        private ValidatorInterface $validator,
        private SessionTokenFactory $tokens,
        private AuthenticationPolicy $authenticationPolicy,
        private ClockInterface $clock,
    ) {
    }

    public function register(RegisterRequest $request): TokenBundle
    {
        $email = mb_strtolower(trim($request->email));
        $this->validate($request, $email);

        if ($this->users->usernameExists($request->username)) {
            throw $this->conflict(['username' => ['username_not_unique']]);
        }
        if ($this->users->emailExists($email)) {
            throw $this->conflict(['email' => ['email_not_unique']]);
        }

        $now = $this->clock->now();
        $passwordHasher = $this->passwordHashers->getPasswordHasher(User::class);
        $user = new User(
            $request->username,
            $email,
            $passwordHasher->hash($request->password),
            $request->language,
            $now,
        );
        $this->authenticationPolicy->assertCanIssueTokens($user);
        $bundle = $this->tokens->create($user, $now);

        try {
            $this->entityManager->wrapInTransaction(function () use ($user, $bundle): void {
                $this->entityManager->persist($user);
                $this->entityManager->persist($bundle->session);
                $this->entityManager->persist($bundle->refreshTokenEntity);
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->conflict(
                [
                    'username' => ['username_or_email_not_unique'],
                    'email' => ['username_or_email_not_unique'],
                ],
                $exception,
            );
        }

        return $bundle;
    }

    private function validate(RegisterRequest $request, string $email): void
    {
        $fields = [];

        if (1 !== preg_match('/^[A-Za-z0-9_-]{4,32}$/D', $request->username)) {
            $fields['username'][] = 'username_invalid';
        }
        if (
            strlen($email) > 254
            || 0 !== $this->validator->validate($email, new Email())->count()
        ) {
            $fields['email'][] = 'email_invalid';
        }

        $password = $request->password;
        if (
            strlen($password) < 8
            || strlen($password) > 64
            || 1 !== preg_match('/^[\x21-\x7E]+$/D', $password)
        ) {
            $fields['password'][] = 'password_invalid_format';
        } else {
            $categories = (int) (1 === preg_match('/[a-z]/', $password))
                + (int) (1 === preg_match('/[A-Z]/', $password))
                + (int) (1 === preg_match('/[0-9]/', $password))
                + (int) (1 === preg_match('/[^A-Za-z0-9]/', $password));
            if ($categories < 3) {
                $fields['password'][] = 'password_not_complex_enough';
            }
        }

        if ([] !== $fields) {
            throw new ApiException(
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
                'VALIDATION_FAILED',
                'validation_failed',
                $fields,
            );
        }
    }

    /**
     * @param array<string, list<string>> $fields
     */
    private function conflict(
        array $fields,
        ?UniqueConstraintViolationException $previous = null,
    ): ApiException {
        return new ApiException(
            JsonResponse::HTTP_CONFLICT,
            'ACCOUNT_ALREADY_EXISTS',
            'account_already_exists',
            $fields,
            previous: $previous,
        );
    }
}
