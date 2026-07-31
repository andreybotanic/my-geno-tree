<?php

namespace App\Entity;

use App\Repository\MediaObjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MediaObjectRepository::class)]
#[ORM\Index(columns: ['owner_id', 'created_at'], name: 'idx_media_owner_created')]
class MediaObject
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    /** @var Collection<int, MediaVariant> */
    #[ORM\OneToMany(
        targetEntity: MediaVariant::class,
        mappedBy: 'media',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $variants;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $owner, \DateTimeImmutable $createdAt)
    {
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->createdAt = $createdAt;
        $this->variants = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id ?? throw new \LogicException('Media ID is unavailable.');
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    /** @return Collection<int, MediaVariant> */
    public function getVariants(): Collection
    {
        return $this->variants;
    }

    public function addVariant(MediaVariant $variant): void
    {
        if (!$this->variants->contains($variant)) {
            $this->variants->add($variant);
        }
    }

    public function variant(MediaVariantType $type): ?MediaVariant
    {
        foreach ($this->variants as $variant) {
            if ($variant->getType() === $type) {
                return $variant;
            }
        }

        return null;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
