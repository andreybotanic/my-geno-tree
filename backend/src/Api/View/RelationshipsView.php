<?php

namespace App\Api\View;

final readonly class RelationshipsView
{
    /**
     * @param list<RelationshipPersonView> $people
     * @param list<ParentLinkView>         $parentLinks
     * @param list<MarriagePeriodView>     $marriages
     */
    public function __construct(
        public array $people,
        public array $parentLinks,
        public array $marriages,
    ) {
    }
}
