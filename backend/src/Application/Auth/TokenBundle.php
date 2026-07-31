<?php

namespace App\Application\Auth;

use App\Entity\AuthSession;
use App\Entity\RefreshToken;

final readonly class TokenBundle
{
    public function __construct(
        public AuthSession $session,
        public RefreshToken $refreshTokenEntity,
        public string $accessToken,
        public string $refreshToken,
        public \DateTimeImmutable $accessExpiresAt,
        public \DateTimeImmutable $refreshExpiresAt,
    ) {
    }
}
