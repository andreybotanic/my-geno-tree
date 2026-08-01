<?php

namespace App\Entity;

use App\Repository\MediaAttachmentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MediaAttachmentRepository::class)]
class MediaAttachment
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    #[ORM\OneToOne(cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MediaObject $media;

    #[ORM\Column(enumType: MediaAttachmentType::class)]
    private MediaAttachmentType $type;

    #[ORM\OneToOne(inversedBy: 'avatarAttachment')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $user;

    #[ORM\OneToOne(inversedBy: 'photoAttachment')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Person $person;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    private function __construct(
        MediaObject $media,
        MediaAttachmentType $type,
        ?User $user,
        ?Person $person,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->media = $media;
        $this->type = $type;
        $this->user = $user;
        $this->person = $person;
        $this->createdAt = $createdAt;
    }

    public static function avatar(
        MediaObject $media,
        User $user,
        \DateTimeImmutable $createdAt,
    ): self {
        $attachment = new self(
            $media,
            MediaAttachmentType::AVATAR,
            $user,
            null,
            $createdAt,
        );
        $user->attachAvatar($attachment, $createdAt);

        return $attachment;
    }

    public static function personPhoto(
        MediaObject $media,
        Person $person,
        \DateTimeImmutable $createdAt,
    ): self {
        $attachment = new self(
            $media,
            MediaAttachmentType::PERSON_PHOTO,
            null,
            $person,
            $createdAt,
        );
        $person->attachPhoto($attachment, $createdAt);

        return $attachment;
    }

    public function getId(): Uuid
    {
        return $this->id ?? throw new \LogicException('Attachment ID is unavailable.');
    }

    public function getMedia(): MediaObject
    {
        return $this->media;
    }

    public function getType(): MediaAttachmentType
    {
        return $this->type;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getPerson(): ?Person
    {
        return $this->person;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
