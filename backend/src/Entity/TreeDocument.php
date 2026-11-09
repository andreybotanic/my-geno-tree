<?php

namespace App\Entity;

use App\Repository\TreeDocumentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TreeDocumentRepository::class)]
#[ORM\Index(columns: ['tree_id', 'id'], name: 'idx_document_tree_page')]
class TreeDocument
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?FamilyTree $tree;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Person $person;

    #[ORM\Column(length: 255, unique: true)]
    private string $storagePath;

    #[ORM\Column(length: 255)]
    private string $originalName;

    #[ORM\Column(length: 50)]
    private string $mimeType;

    #[ORM\Column]
    private int $originalSizeBytes;

    #[ORM\Column(length: 64)]
    private string $checksum;

    #[ORM\Column(length: 20)]
    private string $status = 'queued';

    #[ORM\Column]
    private int $generation = 1;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $errorCode = null;

    #[ORM\Column]
    private bool $deleted = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        FamilyTree $tree,
        ?Person $person,
        string $storagePath,
        string $originalName,
        string $mimeType,
        int $originalSizeBytes,
        string $checksum,
        \DateTimeImmutable $now,
    ) {
        if (null !== $person && !$person->getTree()->getId()->equals($tree->getId())) {
            throw new \InvalidArgumentException('Document person must belong to its tree.');
        }
        $this->id = Uuid::v7();
        $this->tree = $tree;
        $this->person = $person;
        $this->storagePath = $storagePath;
        $this->originalName = $originalName;
        $this->mimeType = $mimeType;
        $this->originalSizeBytes = $originalSizeBytes;
        $this->checksum = $checksum;
        $this->createdAt = $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id ?? throw new \LogicException('Document ID is unavailable.');
    }

    public function getTree(): ?FamilyTree
    {
        return $this->tree;
    }

    public function getPerson(): ?Person
    {
        return $this->person;
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getOriginalSizeBytes(): int
    {
        return $this->originalSizeBytes;
    }

    public function getChecksum(): string
    {
        return $this->checksum;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getGeneration(): int
    {
        return $this->generation;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function queued(\DateTimeImmutable $now): void
    {
        ++$this->generation;
        $this->transition('queued', $now);
    }

    public function transition(string $status, \DateTimeImmutable $now, ?string $errorCode = null): void
    {
        if (!in_array($status, ['queued', 'processing', 'ready', 'failed'], true)) {
            throw new \InvalidArgumentException('Unknown document status.');
        }
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->updatedAt = $now;
    }

    public function delete(\DateTimeImmutable $now, bool $detachTree = false): void
    {
        $this->deleted = true;
        $this->person = null;
        if ($detachTree) {
            $this->tree = null;
        }
        $this->transition('queued', $now);
    }
}
