<?php

namespace App\Api\View;

use App\Api\Mapping\MapObject;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

#[Map(source: \stdClass::class)]
final readonly class PersonDetailsView
{
    /**
     * @param list<EventView> $events
     * @param array{canEdit: bool, canDelete: bool, removableParentLinkIds: list<string>,
     *     removableMarriageIds: list<string>} $permissions
     */
    public function __construct(
        public string $etag,
        #[Map(transform: new MapObject(PersonView::class))] public PersonView $person,
        #[Map(transform: new MapObject(RelationshipsView::class))] public RelationshipsView $relationships,
        #[Map(transform: new MapCollection(targetClass: EventView::class))] public array $events,
        public array $permissions,
    ) {
    }
}
