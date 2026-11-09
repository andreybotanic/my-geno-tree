<?php

namespace App\Api\View;

use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

#[Map(source: \stdClass::class)]
final readonly class RelationshipsView
{
    /**
     * @param list<RelationshipPersonView> $people
     * @param list<ParentLinkView>         $parentLinks
     * @param list<MarriagePeriodView>     $marriages
     */
    public function __construct(
        #[Map(transform: new MapCollection(targetClass: RelationshipPersonView::class))] public array $people,
        #[Map(transform: new MapCollection(targetClass: ParentLinkView::class))] public array $parentLinks,
        #[Map(transform: new MapCollection(targetClass: MarriagePeriodView::class))] public array $marriages,
    ) {
    }
}
