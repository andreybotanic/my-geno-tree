<?php

namespace App\Application\TreeMerge;

use App\Application\Tree\ChangeSet\TreeChangeSet;

final readonly class ValidatedTreeMergeProposal
{
    /** @param array<string, mixed> $proposal
     * @param list<string> $questions
     */
    public function __construct(public array $proposal, public array $questions, public ?TreeChangeSet $changes, public bool $hasChanges)
    {
    }
}
