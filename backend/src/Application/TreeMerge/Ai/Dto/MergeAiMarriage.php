<?php

namespace App\Application\TreeMerge\Ai\Dto;

final class MergeAiMarriage
{
    public string $first = '';
    public string $second = '';
    /** unknown, YYYY[-MM[-DD]], ~YYYY, <YYYY, >YYYY or YYYY..YYYY. */
    public string $marriageDate = 'unknown';
    /** Same date notation; null means no stated dissolution. */
    public ?string $dissolutionDate = null;
    public bool $newPeriod = false;
    /** @var list<MergeAiEvidence> */
    public array $evidence = [];
}
