<?php

namespace App\Api\View;

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
        public TreeView $tree,
        public string $etag,
        public ?string $layoutRevision,
        public ?array $layout,
        public ?array $bloodNetworkState,
        public array $people,
        public array $parentLinks,
        public array $marriages,
    ) {
    }
}
