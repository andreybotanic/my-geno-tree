<?php

namespace App\Api;

use App\Entity\User;

final class UserViewFactory
{
    /**
     * @return array{
     *     id: string,
     *     username: string,
     *     email: string,
     *     preferredLanguage: string,
     *     avatarUrl: null,
     *     createdAt: string
     * }
     */
    public function create(User $user): array
    {
        return [
            'id' => $user->getId()->toRfc4122(),
            'username' => $user->getUsername(),
            'email' => $user->getEmail(),
            'preferredLanguage' => $user->getPreferredLanguage(),
            'avatarUrl' => null,
            'createdAt' => $user->getCreatedAt()->format(DATE_ATOM),
        ];
    }
}
