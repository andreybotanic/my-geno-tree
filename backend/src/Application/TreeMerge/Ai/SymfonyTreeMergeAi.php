<?php

namespace App\Application\TreeMerge\Ai;

use App\Application\TreeMerge\TreeMergeAgentScope;
use App\Entity\TreeMergeDraft;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Target;

#[AsAlias(TreeMergeAiInterface::class, when: ['dev', 'prod'])]
final readonly class SymfonyTreeMergeAi implements TreeMergeAiInterface
{
    public function __construct(#[Target('treeMerge')] private AgentInterface $agent, private TreeMergeAgentScope $scope)
    {
    }

    public function respond(TreeMergeDraft $draft, string $attemptId): void
    {
        // Only explicit tool commands can change the chat; free-form model output has no effects.
        $this->scope->run($draft, $attemptId, fn () => $this->agent->call(MergePrompt::input($draft))->getResult());
    }
}
