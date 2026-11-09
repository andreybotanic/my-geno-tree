<?php

namespace App\Api\View;

use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Uid\Uuid;

#[Map(source: \stdClass::class)]
final readonly class TreeSummaryView
{
    public function __construct(
        #[Map(source: 'tree.id')] public Uuid $id,
        #[Map(source: 'tree.name')] public string $name,
        public int $personCount,
        #[Map(source: 'tree.createdAt')] public \DateTimeImmutable $createdAt,
        #[Map(source: 'tree.updatedAt')] public \DateTimeImmutable $updatedAt,
        public string $etag,
        public ?TreeReferenceView $reference,
    ) {
    }
}
