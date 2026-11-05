<?php

namespace App\Security;

use App\Entity\TreeMergeDraft;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Authenticated chat/attempt scope, present only while an internal MCP tool executes. */
#[Exclude]
final readonly class TreeMergeAgentContext
{
    public string $draftId;
    public string $treeId;
    public int $version;

    public function __construct(TreeMergeDraft $draft, public string $attemptId)
    {
        $this->draftId = $draft->id->toRfc4122();
        $this->treeId = $draft->tree->getId()->toRfc4122();
        $this->version = $draft->version;
    }
}
