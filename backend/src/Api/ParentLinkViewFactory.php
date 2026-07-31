<?php

namespace App\Api;

use App\Entity\ParentLink;

final readonly class ParentLinkViewFactory
{
    /**
     * @return array<string, mixed>
     */
    public function create(ParentLink $link): array
    {
        return [
            'id'       => $link->getId()->toRfc4122(),
            'parentId' => $link->getParent()->getId()->toRfc4122(),
            'childId'  => $link->getChild()->getId()->toRfc4122(),
            'role'     => $link->getRole()->value,
        ];
    }
}
