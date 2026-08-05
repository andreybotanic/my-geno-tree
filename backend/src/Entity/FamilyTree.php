<?php

namespace App\Entity;

use App\Repository\FamilyTreeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: FamilyTreeRepository::class)]
#[ORM\Table(name: 'family_tree')]
#[ORM\UniqueConstraint(
    name: 'uniq_family_tree_owner_name',
    columns: ['owner_id', 'name'],
)]
#[ORM\Index(columns: ['owner_id', 'updated_at'], name: 'idx_tree_owner_updated')]
class FamilyTree
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column]
    private int $version = 1;

    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'reference_primary_person_id', nullable: true, onDelete: 'SET NULL')]
    private ?Person $referencePrimaryPerson = null;

    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'reference_secondary_person_id', nullable: true, onDelete: 'SET NULL')]
    private ?Person $referenceSecondaryPerson = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $bloodNetworkState = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        User $owner,
        string $name,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->name = $name;
        $this->createdAt = $createdAt;
        $this->updatedAt = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getReferencePrimaryPerson(): ?Person
    {
        return $this->referencePrimaryPerson;
    }

    public function getReferenceSecondaryPerson(): ?Person
    {
        return $this->referenceSecondaryPerson;
    }

    public function referencePerson(Person $person): void
    {
        $this->assertPersonBelongsToTree($person);
        $this->referencePrimaryPerson = $person;
        $this->referenceSecondaryPerson = null;
    }

    public function referenceMarriage(Person $first, Person $second): void
    {
        $this->assertPersonBelongsToTree($first);
        $this->assertPersonBelongsToTree($second);
        if ($first === $second) {
            throw new \LogicException('A reference marriage requires two people.');
        }

        if ($first->getId()->toRfc4122() < $second->getId()->toRfc4122()) {
            $this->referencePrimaryPerson = $first;
            $this->referenceSecondaryPerson = $second;
        } else {
            $this->referencePrimaryPerson = $second;
            $this->referenceSecondaryPerson = $first;
        }
    }

    public function clearReference(): void
    {
        $this->referencePrimaryPerson = null;
        $this->referenceSecondaryPerson = null;
    }

    public function references(Person $person): bool
    {
        return $this->referencePrimaryPerson === $person
            || $this->referenceSecondaryPerson === $person;
    }

    /** @return array<string, mixed>|null */
    public function getBloodNetworkState(): ?array
    {
        return $this->bloodNetworkState;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function rename(string $name, \DateTimeImmutable $changedAt): bool
    {
        if ($name === $this->name) {
            return false;
        }

        $this->name = $name;
        $this->touch($changedAt);

        return true;
    }

    /** @param array<string, mixed> $state */
    public function saveBloodNetworkState(array $state, \DateTimeImmutable $changedAt): void
    {
        $this->bloodNetworkState = $state;
        $this->touch($changedAt);
    }

    public function touch(\DateTimeImmutable $changedAt): void
    {
        ++$this->version;
        $this->updatedAt = $changedAt;
    }

    private function assertPersonBelongsToTree(Person $person): void
    {
        if ($person->getTree() !== $this) {
            throw new \LogicException('A reference person must belong to the tree.');
        }
    }
}
