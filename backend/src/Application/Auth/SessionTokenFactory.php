<?php

namespace App\Application\Auth;

use App\Entity\AuthSession;
use App\Entity\RefreshToken;
use App\Entity\User;

final class SessionTokenFactory
{
    private const string ACCESS_LIFETIME = 'PT15M';
    private const string REFRESH_LIFETIME = 'P30D';

    public function create(User $user, \DateTimeImmutable $now): TokenBundle
    {
        $accessToken = $this->generateToken();
        $refreshToken = $this->generateToken();
        $accessExpiresAt = $now->add(new \DateInterval(self::ACCESS_LIFETIME));
        $refreshExpiresAt = $now->add(new \DateInterval(self::REFRESH_LIFETIME));
        $session = new AuthSession(
            $user,
            hash('sha256', $accessToken),
            $accessExpiresAt,
            $refreshExpiresAt,
            $now,
        );
        $refreshTokenEntity = new RefreshToken(
            $session,
            hash('sha256', $refreshToken),
            $refreshExpiresAt,
            $now,
        );

        return new TokenBundle(
            $session,
            $refreshTokenEntity,
            $accessToken,
            $refreshToken,
            $accessExpiresAt,
            $refreshExpiresAt,
        );
    }

    public function rotate(AuthSession $session, \DateTimeImmutable $now): TokenBundle
    {
        $accessToken = $this->generateToken();
        $refreshToken = $this->generateToken();
        $accessExpiresAt = $now->add(new \DateInterval(self::ACCESS_LIFETIME));
        $refreshExpiresAt = $now->add(new \DateInterval(self::REFRESH_LIFETIME));

        $session->rotateAccessToken(
            hash('sha256', $accessToken),
            $accessExpiresAt,
            $now,
        );
        $refreshTokenEntity = new RefreshToken(
            $session,
            hash('sha256', $refreshToken),
            $refreshExpiresAt,
            $now,
        );

        return new TokenBundle(
            $session,
            $refreshTokenEntity,
            $accessToken,
            $refreshToken,
            $accessExpiresAt,
            $refreshExpiresAt,
        );
    }

    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
