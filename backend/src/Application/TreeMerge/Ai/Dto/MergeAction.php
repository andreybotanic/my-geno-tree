<?php

namespace App\Application\TreeMerge\Ai\Dto;

enum MergeAction: string
{
    case PROPOSAL = 'proposal';
    case CLARIFICATION = 'clarification';
    case TREE_ANSWER = 'tree_answer';
    case REFUSAL = 'refusal';
}
