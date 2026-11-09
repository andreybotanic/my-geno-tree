<?php

namespace App\Api\View;

use App\Entity\FamilyTree;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Uid\Uuid;

#[Map(source: FamilyTree::class)]
final readonly class TreeView
{
    public function __construct(
        public Uuid $id,
        public string $name,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        #[Map(source: 'referencePrimaryPerson', transform: [TreeReferenceView::class, 'reference'])]
        public ?TreeReferenceView $reference,
    ) {
    }
}
