<?php

namespace App\Application\TreeMerge\Ai\Dto;

final class MergeAiProposal
{
    /** @var list<MergeAiPerson> */
    public array $people = [];
    /** @var list<MergeAiParent> */
    public array $parents = [];
    /** @var list<MergeAiMarriage> */
    public array $marriages = [];
    /** @var list<string> */
    public array $questions = [];
}
