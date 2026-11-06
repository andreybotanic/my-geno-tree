<?php

namespace App\Entity;

use App\Repository\TreeLayoutRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TreeLayoutRepository::class)]
#[ORM\Table(name: 'tree_layout')]
#[ORM\UniqueConstraint(
    name: 'uniq_tree_layout_anchor',
    columns: ['tree_id', 'primary_person_id', 'secondary_person_id'],
)]
#[ORM\Index(columns: ['tree_id', 'last_used_at', 'id'], name: 'idx_tree_layout_lru')]
class TreeLayout
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private FamilyTree $tree;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'primary_person_id', nullable: false, onDelete: 'CASCADE')]
    private Person $primaryPerson;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'secondary_person_id', nullable: true, onDelete: 'CASCADE')]
    private ?Person $secondaryPerson;

    #[ORM\Column(length: 43)]
    private string $layoutRevision;

    #[ORM\Column]
    private int $algorithmVersion;

    /** @var array<string, array{0: float|int, 1: float|int}> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $positions;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastUsedAt;

    /**
     * @param array<string, array{0: float|int, 1: float|int}> $positions
     */
    public function __construct(
        FamilyTree $tree,
        Person $primaryPerson,
        ?Person $secondaryPerson,
        string $layoutRevision,
        int $algorithmVersion,
        array $positions,
        \DateTimeImmutable $createdAt,
    ) {
        $treeId = $tree->getId()->toRfc4122();
        if (
            $primaryPerson->getTree()->getId()->toRfc4122() !== $treeId
            || (null !== $secondaryPerson
                && $secondaryPerson->getTree()->getId()->toRfc4122() !== $treeId)
        ) {
            throw new \LogicException('Layout anchor people must belong to the tree.');
        }
        if (null !== $secondaryPerson
            && $primaryPerson->getId()->toRfc4122() >= $secondaryPerson->getId()->toRfc4122()) {
            throw new \LogicException('A marriage layout anchor must use canonical person order.');
        }

        $this->id = Uuid::v7();
        $this->tree = $tree;
        $this->primaryPerson = $primaryPerson;
        $this->secondaryPerson = $secondaryPerson;
        $this->createdAt = $createdAt;
        $this->replace($layoutRevision, $algorithmVersion, $positions, $createdAt);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTree(): FamilyTree
    {
        return $this->tree;
    }

    public function getPrimaryPerson(): Person
    {
        return $this->primaryPerson;
    }

    public function getSecondaryPerson(): ?Person
    {
        return $this->secondaryPerson;
    }

    public function getLayoutRevision(): string
    {
        return $this->layoutRevision;
    }

    public function getAlgorithmVersion(): int
    {
        return $this->algorithmVersion;
    }

    /** @return array<string, array{0: float|int, 1: float|int}> */
    public function getPositions(): array
    {
        return $this->positions;
    }

    public function getLastUsedAt(): \DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @param array<string, array{0: float|int, 1: float|int}> $positions
     */
    public function replace(
        string $layoutRevision,
        int $algorithmVersion,
        array $positions,
        \DateTimeImmutable $usedAt,
    ): void {
        $this->layoutRevision = $layoutRevision;
        $this->algorithmVersion = $algorithmVersion;
        $this->positions = $positions;
        $this->lastUsedAt = $usedAt;
    }
}
