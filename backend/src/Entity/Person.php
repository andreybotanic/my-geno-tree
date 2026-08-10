<?php

namespace App\Entity;

use App\Entity\Value\Gender;
use App\Entity\Value\GenealogicalDate;
use App\Entity\Value\KnownText;
use App\Repository\PersonRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PersonRepository::class)]
#[ORM\Index(columns: ['tree_id', 'created_at'], name: 'idx_person_tree_created')]
class Person
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private FamilyTree $tree;

    #[ORM\Embedded(class: KnownText::class, columnPrefix: 'birth_surname_')]
    private KnownText $birthSurname;

    #[ORM\Embedded(class: KnownText::class, columnPrefix: 'given_name_')]
    private KnownText $givenName;

    #[ORM\Embedded(class: KnownText::class, columnPrefix: 'patronymic_')]
    private KnownText $patronymic;

    #[ORM\Column(enumType: Gender::class)]
    private Gender $gender;

    #[ORM\Embedded(class: GenealogicalDate::class, columnPrefix: 'birth_date_')]
    private GenealogicalDate $birthDate;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?EventPlace $birthPlace = null;

    #[ORM\Column]
    private bool $deceased;

    #[ORM\Embedded(class: GenealogicalDate::class, columnPrefix: 'death_date_')]
    private GenealogicalDate $storedDeathDate;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?EventPlace $deathPlace = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\OneToOne(mappedBy: 'person', targetEntity: MediaAttachment::class)]
    private ?MediaAttachment $photoAttachment = null;

    public function __construct(
        FamilyTree $tree,
        KnownText $birthSurname,
        KnownText $givenName,
        KnownText $patronymic,
        Gender $gender,
        GenealogicalDate $birthDate,
        ?GenealogicalDate $deathDate,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->tree = $tree;
        $this->createdAt = $createdAt;
        $this->update(
            $birthSurname,
            $givenName,
            $patronymic,
            $gender,
            $birthDate,
            $deathDate,
            $createdAt,
        );
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTree(): FamilyTree
    {
        return $this->tree;
    }

    public function getBirthSurname(): KnownText
    {
        return $this->birthSurname;
    }

    public function getGivenName(): KnownText
    {
        return $this->givenName;
    }

    public function getPatronymic(): KnownText
    {
        return $this->patronymic;
    }

    public function getGender(): Gender
    {
        return $this->gender;
    }

    public function getBirthDate(): GenealogicalDate
    {
        return $this->birthDate;
    }

    public function getDeathDate(): ?GenealogicalDate
    {
        return $this->deceased ? $this->storedDeathDate : null;
    }

    public function getBirthPlace(): ?EventPlace
    {
        return $this->birthPlace;
    }

    public function getDeathPlace(): ?EventPlace
    {
        return $this->deceased ? $this->deathPlace : null;
    }

    public function changeBirthPlace(?EventPlace $place, \DateTimeImmutable $changedAt): void
    {
        $this->birthPlace = $place;
        $this->updatedAt = $changedAt;
    }

    public function changeDeathPlace(?EventPlace $place, \DateTimeImmutable $changedAt): void
    {
        if (!$this->deceased && null !== $place) {
            throw new \LogicException('A living person cannot have a death place.');
        }
        $this->deathPlace = $place;
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

    public function getPhotoAttachment(): ?MediaAttachment
    {
        return $this->photoAttachment;
    }

    public function attachPhoto(
        MediaAttachment $attachment,
        \DateTimeImmutable $changedAt,
    ): void {
        $this->photoAttachment = $attachment;
        $this->updatedAt = $changedAt;
    }

    public function detachPhoto(\DateTimeImmutable $changedAt): void
    {
        $this->photoAttachment = null;
        $this->updatedAt = $changedAt;
    }

    public function update(
        KnownText $birthSurname,
        KnownText $givenName,
        KnownText $patronymic,
        Gender $gender,
        GenealogicalDate $birthDate,
        ?GenealogicalDate $deathDate,
        \DateTimeImmutable $changedAt,
    ): void {
        $this->birthSurname = $birthSurname;
        $this->givenName = $givenName;
        $this->patronymic = $patronymic;
        $this->gender = $gender;
        $this->birthDate = $birthDate;
        $this->deceased = null !== $deathDate;
        $this->storedDeathDate = $deathDate ?? GenealogicalDate::unknown();
        if (!$this->deceased) {
            $this->deathPlace = null;
        }
        $this->updatedAt = $changedAt;
    }
}
