<?php

namespace App\Entity;

use App\Repository\TreeMergeDraftRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: TreeMergeDraftRepository::class)]
class TreeMergeDraft
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public private(set) Uuid $id;

    #[ORM\Column(type: 'uuid')]
    public private(set) UuidV7 $conversationId;

    public User $owner { get => $this->tree->getOwner(); }

    #[ORM\Column]
    public private(set) int $version = 1;

    #[ORM\Column]
    public private(set) int $proposalVersion = 0;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    public private(set) ?array $proposal = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    public private(set) ?array $result = null;

    #[ORM\Column(length: 30)]
    public private(set) string $state = 'collecting';

    #[ORM\Column(length: 20)]
    public private(set) string $chatState = 'idle';

    #[ORM\Column(length: 36, nullable: true)]
    public private(set) ?string $attemptId = null;

    #[ORM\Column(length: 64, nullable: true)]
    public private(set) ?string $attemptRequestId = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $attemptStartedAt = null;

    #[ORM\Column]
    public private(set) \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\OneToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public private(set) FamilyTree $tree,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public private(set) ?Person $referencePerson,
        #[ORM\Column(length: 100)]
        public private(set) string $treeEtag,
        #[ORM\Column]
        public private(set) \DateTimeImmutable $createdAt,
        #[ORM\Column(type: 'uuid', nullable: true)]
        public private(set) ?Uuid $chatSessionId = null,
    ) {
        $this->id = Uuid::v7();
        $this->conversationId = Uuid::v7();
        $this->updatedAt = $createdAt;
    }

    public function messageAdded(\DateTimeImmutable $now, Uuid $sessionId): void
    {
        $this->chatSessionId = $sessionId;
        $this->chatState = 'idle';
        $this->attemptId = null;
        ++$this->version;
        $this->updatedAt = $now;
    }

    public function beginAttempt(\DateTimeImmutable $now, ?string $requestId = null): string
    {
        $this->attemptId = Uuid::v7()->toRfc4122();
        $this->attemptRequestId = $requestId;
        $this->attemptStartedAt = $now;
        $this->chatState = 'processing';
        ++$this->version;
        $this->updatedAt = $now;

        return $this->attemptId;
    }

    public function finishAttempt(\DateTimeImmutable $now, bool $failed = false): void
    {
        $this->chatState = $failed ? 'error' : 'idle';
        ++$this->version;
        $this->updatedAt = $now;
    }

    public function reset(\DateTimeImmutable $now, ?Person $referencePerson = null, ?Uuid $sessionId = null): void
    {
        $this->chatSessionId = $sessionId ?? $this->chatSessionId;
        $this->referencePerson = $referencePerson;
        $this->conversationId = Uuid::v7();
        $this->proposal = $this->result = null;
        $this->state = 'collecting';
        $this->chatState = 'idle';
        $this->attemptId = $this->attemptRequestId = null;
        $this->attemptStartedAt = null;
        ++$this->proposalVersion;
        ++$this->version;
        $this->updatedAt = $now;
    }

    /** @param array<string, mixed> $proposal */
    public function propose(array $proposal, string $etag, string $state, \DateTimeImmutable $now, bool $supersedeAttempt = false): void
    {
        $this->proposal = $proposal;
        $this->result = null;
        $this->treeEtag = $etag;
        $this->state = $state;
        if ($supersedeAttempt) {
            $this->chatState = 'idle';
            $this->attemptId = null;
        }
        ++$this->proposalVersion;
        ++$this->version;
        $this->updatedAt = $now;
    }

    /** @param array<string, mixed> $result */
    public function applied(array $result, \DateTimeImmutable $now): void
    {
        $this->result = $result;
        $this->state = 'applied';
        ++$this->version;
        $this->updatedAt = $now;
    }
}
