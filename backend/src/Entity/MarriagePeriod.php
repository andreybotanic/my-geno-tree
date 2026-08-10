<?php

namespace App\Entity;

use App\Entity\Value\GenealogicalDate;
use App\Repository\MarriagePeriodRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MarriagePeriodRepository::class)]
#[ORM\Index(columns: ['tree_id'], name: 'idx_marriage_period_tree')]
#[ORM\Index(
    columns: ['male_person_id', 'female_person_id'],
    name: 'idx_marriage_period_pair',
)]
class MarriagePeriod
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private FamilyTree $tree;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Person $malePerson;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Person $femalePerson;

    #[ORM\Embedded(class: GenealogicalDate::class, columnPrefix: 'marriage_date_')]
    private GenealogicalDate $marriageDate;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?EventPlace $marriagePlace = null;

    #[ORM\Column]
    private bool $dissolved;

    #[ORM\Embedded(
        class: GenealogicalDate::class,
        columnPrefix: 'dissolution_date_',
    )]
    private GenealogicalDate $storedDissolutionDate;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?EventPlace $dissolutionPlace = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        FamilyTree $tree,
        Person $malePerson,
        Person $femalePerson,
        GenealogicalDate $marriageDate,
        ?GenealogicalDate $dissolutionDate,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->tree = $tree;
        $this->malePerson = $malePerson;
        $this->femalePerson = $femalePerson;
        $this->createdAt = $createdAt;
        $this->changeDates($marriageDate, $dissolutionDate, $createdAt);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTree(): FamilyTree
    {
        return $this->tree;
    }

    public function getMalePerson(): Person
    {
        return $this->malePerson;
    }

    public function getFemalePerson(): Person
    {
        return $this->femalePerson;
    }

    public function getMarriageDate(): GenealogicalDate
    {
        return $this->marriageDate;
    }

    public function getDissolutionDate(): ?GenealogicalDate
    {
        return $this->dissolved ? $this->storedDissolutionDate : null;
    }

    public function getMarriagePlace(): ?EventPlace
    {
        return $this->marriagePlace;
    }

    public function getDissolutionPlace(): ?EventPlace
    {
        return $this->dissolved ? $this->dissolutionPlace : null;
    }

    public function changeMarriagePlace(?EventPlace $place, \DateTimeImmutable $changedAt): void
    {
        $this->marriagePlace = $place;
        $this->updatedAt = $changedAt;
    }

    public function changeDissolutionPlace(?EventPlace $place, \DateTimeImmutable $changedAt): void
    {
        if (!$this->dissolved && null !== $place) {
            throw new \LogicException('An active marriage cannot have a dissolution place.');
        }
        $this->dissolutionPlace = $place;
        $this->updatedAt = $changedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function changeDates(
        GenealogicalDate $marriageDate,
        ?GenealogicalDate $dissolutionDate,
        \DateTimeImmutable $changedAt,
    ): void {
        $this->marriageDate = $marriageDate;
        $this->dissolved = null !== $dissolutionDate;
        $this->storedDissolutionDate = $dissolutionDate
            ?? GenealogicalDate::unknown();
        if (!$this->dissolved) {
            $this->dissolutionPlace = null;
        }
        $this->updatedAt = $changedAt;
    }
}
