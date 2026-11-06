<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['user_id', 'number'])]
class ChatBan
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;
    #[ORM\Column]
    private int $chargedTokens = 0;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column]
        private int $number,
        #[ORM\Column]
        private \DateTimeImmutable $startsAt,
        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $endsAt,
    ) {
        if ($number < 1 || (null !== $endsAt && $endsAt <= $startsAt)) {
            throw new \InvalidArgumentException('Invalid chat ban.');
        }
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

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getChargedTokens(): int
    {
        return $this->chargedTokens;
    }

    public function isActive(\DateTimeImmutable $now): bool
    {
        return null === $this->endsAt || $this->endsAt > $now;
    }

    public function chargeTokens(int $tokens): void
    {
        if ($tokens < 0 || 0 !== $this->chargedTokens) {
            throw new \LogicException('Invalid or repeated chat ban charge.');
        }
        $this->chargedTokens = $tokens;
    }
}
