<?php

namespace App\Api\View;

use App\Application\Auth\TokenBundle;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: TokenBundle::class)]
final readonly class AuthView
{
    public function __construct(
        public string $accessToken,
        #[Map(source: 'accessExpiresAt')] public \DateTimeImmutable $accessTokenExpiresAt,
        #[Map(source: 'session.user')] public UserView $user,
    ) {
    }
}
