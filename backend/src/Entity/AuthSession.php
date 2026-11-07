<?php

namespace App\Entity;

use App\Repository\AuthSessionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AuthSessionRepository::class)]
#[ORM\Index(columns: ['user_id'], name: 'idx_auth_session_user')]
class AuthSession
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64, unique: true)]
    private string $accessTokenHash;

    #[ORM\Column]
    private \DateTimeImmutable $accessExpiresAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastUsedAt;

    public function __construct(
        User $user,
        string $accessTokenHash,
        \DateTimeImmutable $accessExpiresAt,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->accessTokenHash = $accessTokenHash;
        $this->accessExpiresAt = $accessExpiresAt;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $createdAt;
        $this->lastUsedAt = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getAccessTokenHash(): string
    {
        return $this->accessTokenHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): \DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isAccessActive(\DateTimeImmutable $now): bool
    {
        return null === $this->revokedAt && $this->accessExpiresAt > $now;
    }

    public function isRefreshActive(\DateTimeImmutable $now): bool
    {
        return null === $this->revokedAt && $this->expiresAt > $now;
    }

    public function rotateAccessToken(
        string $accessTokenHash,
        \DateTimeImmutable $accessExpiresAt,
        \DateTimeImmutable $usedAt,
    ): void {
        $this->accessTokenHash = $accessTokenHash;
        $this->accessExpiresAt = $accessExpiresAt;
        $this->lastUsedAt = $usedAt;
    }

    public function revoke(\DateTimeImmutable $revokedAt): void
    {
        $this->revokedAt ??= $revokedAt;
    }
}
