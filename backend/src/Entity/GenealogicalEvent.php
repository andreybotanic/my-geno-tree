<?php

namespace App\Entity;

use App\Entity\Value\EventSource;
use App\Entity\Value\EventType;
use App\Entity\Value\GenealogicalDate;
use App\Repository\GenealogicalEventRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: GenealogicalEventRepository::class)]
#[ORM\UniqueConstraint(
    name: 'uniq_automatic_event_source',
    columns: ['person_id', 'type', 'source_entity_id'],
)]
#[ORM\Index(columns: ['person_id', 'created_at'], name: 'idx_event_person_created')]
class GenealogicalEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Person $person;

    #[ORM\Column(enumType: EventType::class)]
    private EventType $type;

    #[ORM\Column(enumType: EventSource::class)]
    private EventSource $source;

    #[ORM\Embedded(class: GenealogicalDate::class, columnPrefix: 'event_date_')]
    private GenealogicalDate $date;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $data;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $sourceEntityType;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $sourceEntityId;

    #[ORM\Column(nullable: true)]
    private ?int $manualOrder;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        Person $person,
        EventType $type,
        EventSource $source,
        GenealogicalDate $date,
        ?string $title,
        ?string $description,
        array $data,
        ?string $sourceEntityType,
        ?Uuid $sourceEntityId,
        ?int $manualOrder,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->person = $person;
        $this->type = $type;
        $this->source = $source;
        $this->date = $date;
        $this->title = $title;
        $this->description = $description;
        $this->data = $data;
        $this->sourceEntityType = $sourceEntityType;
        $this->sourceEntityId = $sourceEntityId;
        $this->manualOrder = $manualOrder;
        $this->createdAt = $createdAt;
        $this->updatedAt = $createdAt;
    }

    public static function automaticPersonLifecycle(
        Person $person,
        EventType $type,
        GenealogicalDate $date,
        \DateTimeImmutable $createdAt,
    ): self {
        return new self(
            $person,
            $type,
            EventSource::AUTOMATIC,
            $date,
            null,
            null,
            [],
            'person',
            $person->getId(),
            null,
            $createdAt,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function automaticRelated(
        Person $person,
        EventType $type,
        GenealogicalDate $date,
        string $sourceEntityType,
        Uuid $sourceEntityId,
        array $data,
        \DateTimeImmutable $createdAt,
    ): self {
        return new self(
            $person,
            $type,
            EventSource::AUTOMATIC,
            $date,
            null,
            null,
            $data,
            $sourceEntityType,
            $sourceEntityId,
            null,
            $createdAt,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function manual(
        Person $person,
        EventType $type,
        GenealogicalDate $date,
        ?string $title,
        ?string $description,
        array $data,
        \DateTimeImmutable $createdAt,
    ): self {
        return new self(
            $person,
            $type,
            EventSource::MANUAL,
            $date,
            $title,
            $description,
            $data,
            null,
            null,
            null,
            $createdAt,
        );
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPerson(): Person
    {
        return $this->person;
    }

    public function getType(): EventType
    {
        return $this->type;
    }

    public function getSource(): EventSource
    {
        return $this->source;
    }

    public function getDate(): GenealogicalDate
    {
        return $this->date;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function getSourceEntityType(): ?string
    {
        return $this->sourceEntityType;
    }

    public function getSourceEntityId(): ?Uuid
    {
        return $this->sourceEntityId;
    }

    public function getManualOrder(): ?int
    {
        return $this->manualOrder;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function changeAutomaticDate(
        GenealogicalDate $date,
        \DateTimeImmutable $changedAt,
    ): void {
        $this->date = $date;
        $this->updatedAt = $changedAt;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function changeAutomatic(
        GenealogicalDate $date,
        array $data,
        \DateTimeImmutable $changedAt,
    ): void {
        $this->date = $date;
        $this->data = $data;
        $this->updatedAt = $changedAt;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function changeManual(
        EventType $type,
        GenealogicalDate $date,
        ?string $title,
        ?string $description,
        array $data,
        \DateTimeImmutable $changedAt,
    ): void {
        $this->type = $type;
        $this->date = $date;
        $this->title = $title;
        $this->description = $description;
        $this->data = $data;
        $this->updatedAt = $changedAt;
    }

    public function changeManualOrder(
        ?int $manualOrder,
        \DateTimeImmutable $changedAt,
    ): void {
        $this->manualOrder = $manualOrder;
        $this->updatedAt = $changedAt;
    }
}
