<?php

namespace App\Application\Tree\ChangeSet;

/** Application operations, independent of the source (chat, merge, or another workflow). */
final readonly class TreeChangeSet
{
    /**
     * @param list<PersonChange>   $people
     * @param list<ParentChange>   $parents
     * @param list<MarriageChange> $marriages
     */
    public function __construct(
        public array $people,
        public array $parents = [],
        public array $marriages = [],
    ) {
    }
}
