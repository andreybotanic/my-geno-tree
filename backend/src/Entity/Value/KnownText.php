<?php

namespace App\Entity\Value;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final class KnownText
{
    #[ORM\Column(enumType: KnowledgeState::class)]
    private KnowledgeState $state;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $value;

    private function __construct(KnowledgeState $state, ?string $value)
    {
        $this->state = $state;
        $this->value = $value;
    }

    public static function known(string $value): self
    {
        return new self(KnowledgeState::KNOWN, $value);
    }

    public static function unknown(): self
    {
        return new self(KnowledgeState::UNKNOWN, null);
    }

    public function isKnown(): bool
    {
        return KnowledgeState::KNOWN === $this->state;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }
}
