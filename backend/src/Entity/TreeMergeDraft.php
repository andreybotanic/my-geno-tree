<?php

namespace App\Entity;

use App\Repository\TreeMergeDraftRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TreeMergeDraftRepository::class)]
#[ORM\Index(columns: ['tree_id', 'updated_at'], name: 'idx_merge_tree_updated')]
class TreeMergeDraft
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public private(set) Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public private(set) User $owner;

    #[ORM\Column]
    public private(set) int $version = 1;

    #[ORM\Column]
    public private(set) int $sourceVersion = 0;

    #[ORM\Column]
    public private(set) int $proposalVersion = 0;

    #[ORM\Column(nullable: true)]
    public private(set) ?int $preparedSourceVersion = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    public private(set) ?array $proposal = null;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: 'json')]
    public private(set) array $corrections = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    public private(set) array $questions = [];

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    public private(set) ?array $result = null;

    #[ORM\Column(length: 30)]
    public private(set) string $state = 'collecting';

    #[ORM\Column(length: 36, nullable: true)]
    public private(set) ?string $attemptId = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?int $attemptSourceVersion = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $attemptStartedAt = null;

    #[ORM\Column(length: 30, nullable: true)]
    public private(set) ?string $stateBeforeAttempt = null;

    /** @var list<array{id: string, requestId: string, role: string, content: string, createdAt: string, source?: bool}> */
    #[ORM\Column(type: 'json')]
    public private(set) array $messages = [];

    #[ORM\Column]
    public private(set) \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public private(set) FamilyTree $tree,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public private(set) ?Person $referencePerson,
        #[ORM\Column(length: 100)]
        public private(set) string $treeEtag,
        #[ORM\Column]
        public private(set) \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->owner = $tree->getOwner();
        $this->updatedAt = $createdAt;
    }

    public function addMessage(string $requestId, string $content, \DateTimeImmutable $now, bool $source = true): void
    {
        $this->messages[] = [
            'id'        => Uuid::v7()->toRfc4122(),
            'requestId' => $requestId,
            'role'      => 'user',
            'content'   => $content,
            'createdAt' => $now->format(\DateTimeInterface::ATOM),
            'source'    => $source,
        ];
        if ($source) {
            ++$this->sourceVersion;
            ++$this->proposalVersion;
            $this->state = 'collecting';
        } else {
            if (!in_array($this->state, ['processing', 'error', 'collecting'], true)) {
                $this->stateBeforeAttempt = $this->state;
            }
            $this->state = 'collecting';
        }
        ++$this->version;
        $this->updatedAt = $now;
    }

    public function beginAttempt(\DateTimeImmutable $now): string
    {
        if (null === $this->stateBeforeAttempt) {
            $this->stateBeforeAttempt = $this->state;
        }
        $this->attemptId = Uuid::v7()->toRfc4122();
        $this->attemptSourceVersion = $this->sourceVersion;
        $this->attemptStartedAt = $now;
        $this->state = 'processing';
        ++$this->version;
        $this->updatedAt = $now;

        return $this->attemptId;
    }

    public function markSource(string $requestId): void
    {
        $pending = array_column($this->sourceMessages(), 'id');
        foreach ($this->messages as &$message) {
            if (in_array($message['id'], $pending, true) && !($message['source'] ?? true)) {
                $message['source'] = true;
                ++$this->sourceVersion;
                ++$this->proposalVersion;
            }
            if ($message['requestId'] === $requestId) {
                break;
            }
        }
    }

    /** @return list<array{id: string, requestId: string, role: string, content: string, createdAt: string, source?: bool}> */
    public function sourceMessages(): array
    {
        $answered = array_column(array_filter($this->messages, static fn ($message) => 'assistant' === $message['role']), 'requestId');

        return array_values(array_filter($this->messages, static fn ($message) => 'user' === $message['role']
            && (($message['source'] ?? true) || !in_array($message['requestId'], $answered, true))));
    }

    public function answer(string $requestId, string $content, \DateTimeImmutable $now): void
    {
        $this->messages[] = ['id' => Uuid::v7()->toRfc4122(), 'requestId' => $requestId,
            'role'                => 'assistant', 'content' => $content, 'createdAt' => $now->format(\DateTimeInterface::ATOM)];
        if ('processing' === $this->state) {
            $this->state = $this->stateBeforeAttempt ?? 'collecting';
        }
        $this->stateBeforeAttempt = null;
        ++$this->version;
        $this->updatedAt = $now;
    }

    public function processingFailed(\DateTimeImmutable $now): void
    {
        $this->state = 'error';
        ++$this->version;
        $this->updatedAt = $now;
    }

    public function cancel(\DateTimeImmutable $now): void
    {
        $this->state = 'cancelled';
        ++$this->version;
        $this->updatedAt = $now;
    }

    /** @param array<string, mixed> $proposal
     * @param list<string> $questions
     */
    public function propose(array $proposal, array $questions, string $etag, string $state, bool $correction, \DateTimeImmutable $now): void
    {
        if ($correction) {
            $this->corrections[] = ['sourceVersion' => ++$this->sourceVersion, 'proposal' => $proposal];
        }
        $this->proposal = $proposal;
        $this->questions = $questions;
        $this->preparedSourceVersion = $this->sourceVersion;
        $this->treeEtag = $etag;
        $this->state = $state;
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
