<?php

namespace App\Api\View;

final readonly class PersonDetailsView
{
    /**
     * @param list<EventView> $events
     * @param array{canEdit: bool, canDelete: bool, removableParentLinkIds: list<string>,
     *     removableMarriageIds: list<string>} $permissions
     */
    public function __construct(
        public string $etag,
        public PersonView $person,
        public RelationshipsView $relationships,
        public array $events,
        public array $permissions,
    ) {
    }
}
