<?php

namespace App\Application\TreeMerge\Ai;

use App\Entity\TreeMergeDraft;
use App\Security\TreeMergeAgentTokenHandler;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Target;

#[AsAlias(TreeMergeAiInterface::class, when: ['dev', 'prod'])]
final readonly class SymfonyTreeMergeAi implements TreeMergeAiInterface
{
    public function __construct(#[Target('treeMerge')] private AgentInterface $agent, private TreeMergeAgentTokenHandler $tokenHandler)
    {
    }

    public function respond(TreeMergeDraft $draft, string $attemptId): void
    {
        // Only MCP commands can change the chat; the external agent's HTTP text has no effects.
        $this->agent->call(MergePrompt::input($draft, $this->tokenHandler->issue($draft), $attemptId))->getResult();
    }
}
