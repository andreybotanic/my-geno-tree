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

    /** @var list<array{id: string, requestId: string, role: string, content: string, createdAt: string}> */
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

    public function addMessage(string $requestId, string $content, \DateTimeImmutable $now): void
    {
        $this->messages[] = [
            'id'        => Uuid::v7()->toRfc4122(),
            'requestId' => $requestId,
            'role'      => 'user',
            'content'   => $content,
            'createdAt' => $now->format(\DateTimeInterface::ATOM),
        ];
        ++$this->sourceVersion;
        ++$this->proposalVersion;
        $this->state = 'collecting';
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
