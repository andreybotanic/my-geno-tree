<?php

namespace App\Api\View;

use App\Entity\GenealogicalEvent;
use App\Entity\Value\EventSource;
use App\Entity\Value\EventType;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Uid\Uuid;

#[Map(source: GenealogicalEvent::class)]
final readonly class EventView
{
    /** @param array<string, mixed>|\stdClass $data */
    public function __construct(
        public Uuid $id,
        #[Map(source: 'person.id')] public Uuid $personId,
        public EventType $type,
        public EventSource $source,
        public GenealogicalDateView $date,
        public ?EventPlaceView $place,
        public ?string $title,
        public ?string $description,
        #[Map(transform: [self::class, 'data'])] public array|\stdClass $data,
        public ?string $sourceEntityType,
        public ?Uuid $sourceEntityId,
        public ?int $manualOrder,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>|\stdClass
     */
    public static function data(array $data): array|\stdClass
    {
        return [] === $data ? new \stdClass() : $data;
    }
}
