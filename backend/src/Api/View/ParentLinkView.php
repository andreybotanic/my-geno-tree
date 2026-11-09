<?php

namespace App\Api\View;

use App\Entity\ParentLink;
use App\Entity\Value\ParentRole;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Uid\Uuid;

#[Map(source: ParentLink::class)]
final readonly class ParentLinkView
{
    public function __construct(
        public Uuid $id,
        #[Map(source: 'parent.id')] public Uuid $parentId,
        #[Map(source: 'child.id')] public Uuid $childId,
        public ParentRole $role,
    ) {
    }
}
