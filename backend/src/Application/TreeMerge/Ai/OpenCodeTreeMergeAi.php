<?php

namespace App\Application\TreeMerge\Ai;

use App\Entity\TreeMergeDraft;
use App\Security\TreeMergeAgentGrant;

final readonly class OpenCodeTreeMergeAi implements TreeMergeAiInterface
{
    public function __construct(private OpenCodeRunner $runner, private TreeMergeAgentGrant $grants)
    {
    }

    public function respond(TreeMergeDraft $draft, string $attemptId): void
    {
        $token = $this->grants->issue($draft, $attemptId);
        try {
            $this->runner->run($draft, MergePrompt::input($draft), $token);
        } finally {
            $this->grants->revoke($token);
        }
    }
}
