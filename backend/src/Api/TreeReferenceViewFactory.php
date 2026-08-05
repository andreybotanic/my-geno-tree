<?php

namespace App\Api;

use App\Entity\FamilyTree;

final readonly class TreeReferenceViewFactory
{
    /**
     * @return array{type: 'person', personId: string}|array{type: 'marriage', personIds: list<string>}|null
     */
    public function create(FamilyTree $tree): ?array
    {
        $primary = $tree->getReferencePrimaryPerson();
        if (null === $primary) {
            return null;
        }

        $secondary = $tree->getReferenceSecondaryPerson();
        if (null === $secondary) {
            return [
                'type'     => 'person',
                'personId' => $primary->getId()->toRfc4122(),
            ];
        }

        return [
            'type'      => 'marriage',
            'personIds' => [
                $primary->getId()->toRfc4122(),
                $secondary->getId()->toRfc4122(),
            ],
        ];
    }
}
