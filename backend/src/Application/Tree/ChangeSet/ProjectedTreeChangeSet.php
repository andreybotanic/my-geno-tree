<?php

namespace App\Application\Tree\ChangeSet;

use App\Entity\MarriagePeriod;
use App\Entity\ParentLink;
use App\Entity\Person;

final readonly class ProjectedTreeChangeSet
{
    /**
     * @param array<string, Person> $people         Detached final person values,
     *                                              keyed by operation reference or existing ID
     * @param array<string, Person> $originals      managed existing people, keyed by existing ID
     * @param list<ParentLink>      $parents        final links referring to detached people
     * @param list<MarriagePeriod>  $marriages      final marriages referring to detached people
     * @param list<ParentLink>      $addedParents
     * @param list<MarriagePeriod>  $addedMarriages
     */
    public function __construct(
        public array $people,
        public array $originals,
        public array $parents,
        public array $marriages,
        public array $addedParents,
        public array $addedMarriages,
        public bool $hasChanges,
    ) {
    }
}
