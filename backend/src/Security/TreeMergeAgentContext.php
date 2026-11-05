<?php

namespace App\Security;

use App\Entity\TreeMergeDraft;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Backend-only chat/attempt scope, present while the Symfony agent executes. */
#[Exclude]
final class TreeMergeAgentContext
{
    public readonly string $draftId;
    public readonly string $treeId;
    public int $version;

    public function __construct(TreeMergeDraft $draft, public readonly string $attemptId)
    {
        $this->draftId = $draft->id->toRfc4122();
        $this->treeId = $draft->tree->getId()->toRfc4122();
        $this->version = $draft->version;
    }
}
