<?php

namespace App\Application\TreeMerge\Ai;

use App\Entity\TreeMergeDraft;

interface TreeMergeAiInterface
{
    public function respond(TreeMergeDraft $draft, string $attemptId): string;
}
