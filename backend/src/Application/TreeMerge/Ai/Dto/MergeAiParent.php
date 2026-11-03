<?php

namespace App\Application\TreeMerge\Ai\Dto;

use App\Entity\Value\ParentRole;

final class MergeAiParent
{
    public string $parent = '';
    public string $child = '';
    public ParentRole $role;
    /** @var list<MergeAiEvidence> */
    public array $evidence = [];
}
