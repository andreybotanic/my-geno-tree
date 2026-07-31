<?php

namespace App\Entity;

use App\Entity\Value\Gender;
use App\Entity\Value\GenealogicalDate;
use App\Entity\Value\KnownText;
use App\Repository\PersonRepository;
use DateTimeImmutable;
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

    #[ORM\Column]
    private bool $deceased;

    #[ORM\Embedded(class: GenealogicalDate::class, columnPrefix: 'death_date_')]
    private GenealogicalDate $storedDeathDate;

    #[ORM\Column]
    private float $x;

    #[ORM\Column]
    private float $y;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        FamilyTree $tree,
        KnownText $birthSurname,
        KnownText $givenName,
        KnownText $patronymic,
        Gender $gender,
        GenealogicalDate $birthDate,
        ?GenealogicalDate $deathDate,
        float $x,
        float $y,
        DateTimeImmutable $createdAt,
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
        $this->moveTo($x, $y, $createdAt);
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

    public function getX(): float
    {
        return $this->x;
    }

    public function getY(): float
    {
        return $this->y;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function update(
        KnownText $birthSurname,
        KnownText $givenName,
        KnownText $patronymic,
        Gender $gender,
        GenealogicalDate $birthDate,
        ?GenealogicalDate $deathDate,
        DateTimeImmutable $changedAt,
    ): void {
        $this->birthSurname = $birthSurname;
        $this->givenName = $givenName;
        $this->patronymic = $patronymic;
        $this->gender = $gender;
        $this->birthDate = $birthDate;
        $this->deceased = null !== $deathDate;
        $this->storedDeathDate = $deathDate ?? GenealogicalDate::unknown();
        $this->updatedAt = $changedAt;
    }

    public function moveTo(
        float $x,
        float $y,
        DateTimeImmutable $changedAt,
    ): void {
        $this->x = $x;
        $this->y = $y;
        $this->updatedAt = $changedAt;
    }
}
