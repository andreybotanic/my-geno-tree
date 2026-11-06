<?php

namespace App\Entity;

use App\Repository\SuspiciousChatMessageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SuspiciousChatMessageRepository::class)]
#[ORM\Index(columns: ['created_at'])]
class SuspiciousChatMessage
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 64, unique: true)]
        private string $operationKey,
        #[ORM\Column(type: 'text')]
        private string $content,
        #[ORM\Column]
        private float $risk,
        #[ORM\Column]
        private int $warningNumber,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getRisk(): float
    {
        return $this->risk;
    }

    public function getWarningNumber(): int
    {
        return $this->warningNumber;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
