<?php

namespace App\Application\Auth;

use App\Api\ApiException;
use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;

final class AuthenticationPolicy
{
    public function assertCanIssueTokens(User $user): void
    {
        if (null !== $user->getEmailVerifiedAt()) {
            return;
        }

        throw new ApiException(
            JsonResponse::HTTP_FORBIDDEN,
            'EMAIL_NOT_VERIFIED',
            'email_not_verified',
        );
    }
}
