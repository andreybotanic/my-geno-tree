<?php

namespace App\Application\TreeMerge\Ai;

use App\Entity\TreeMergeDraft;
use App\Security\TreeMergeAgentAccess;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Target;

#[AsAlias(TreeMergeAiInterface::class, when: ['dev', 'prod'])]
final readonly class SymfonyTreeMergeAi implements TreeMergeAiInterface
{
    public function __construct(#[Target('treeMerge')] private AgentInterface $agent, private TreeMergeAgentAccess $access)
    {
    }

    public function respond(TreeMergeDraft $draft, string $attemptId): void
    {
        // Only explicit tool commands can change the chat; free-form model output has no effects.
        $this->access->run($draft, $attemptId, fn () => $this->agent->call(MergePrompt::input($draft))->getResult());
    }
}
