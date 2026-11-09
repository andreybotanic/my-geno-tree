<?php

namespace App\Api\View;

use App\Api\Mapping\MapObject;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

#[Map(source: \stdClass::class)]
final readonly class TreeSnapshotView
{
    /**
     * @param array<string, mixed>|null $layout
     * @param array<string, mixed>|null $bloodNetworkState
     * @param list<PersonView>          $people
     * @param list<ParentLinkView>      $parentLinks
     * @param list<MarriagePeriodView>  $marriages
     */
    public function __construct(
        #[Map(transform: new MapObject(TreeView::class))] public TreeView $tree,
        public string $etag,
        public ?string $layoutRevision,
        public ?array $layout,
        public ?array $bloodNetworkState,
        #[Map(transform: new MapCollection(targetClass: PersonView::class))] public array $people,
        #[Map(transform: new MapCollection(targetClass: ParentLinkView::class))] public array $parentLinks,
        #[Map(transform: new MapCollection(targetClass: MarriagePeriodView::class))] public array $marriages,
    ) {
    }
}
