<?php

namespace App\Entity;

use App\Repository\MediaVariantRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MediaVariantRepository::class)]
#[ORM\UniqueConstraint(
    name: 'uniq_media_variant_type',
    columns: ['media_id', 'type'],
)]
class MediaVariant
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(inversedBy: 'variants')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MediaObject $media;

    #[ORM\Column(enumType: MediaVariantType::class)]
    private MediaVariantType $type;

    #[ORM\Column(length: 255, unique: true)]
    private string $storagePath;

    #[ORM\Column(length: 50)]
    private string $mimeType;

    #[ORM\Column]
    private int $width;

    #[ORM\Column]
    private int $height;

    #[ORM\Column]
    private int $byteSize;

    public function __construct(
        MediaObject $media,
        MediaVariantType $type,
        string $storagePath,
        string $mimeType,
        int $width,
        int $height,
        int $byteSize,
    ) {
        $this->id = Uuid::v7();
        $this->media = $media;
        $this->type = $type;
        $this->storagePath = $storagePath;
        $this->mimeType = $mimeType;
        $this->width = $width;
        $this->height = $height;
        $this->byteSize = $byteSize;
        $media->addVariant($this);
    }

    public function getId(): Uuid
    {
        return $this->id ?? throw new \LogicException('Variant ID is unavailable.');
    }

    public function getType(): MediaVariantType
    {
        return $this->type;
    }

    public function getMedia(): MediaObject
    {
        return $this->media;
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function getByteSize(): int
    {
        return $this->byteSize;
    }
}
