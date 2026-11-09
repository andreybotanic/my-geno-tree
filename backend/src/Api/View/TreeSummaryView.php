<?php

namespace App\Api\View;

use Symfony\Component\Uid\Uuid;

final readonly class TreeSummaryView
{
    public function __construct(
        public Uuid $id,
        public string $name,
        public int $personCount,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public string $etag,
        public ?TreeReferenceView $reference,
    ) {
    }
}
