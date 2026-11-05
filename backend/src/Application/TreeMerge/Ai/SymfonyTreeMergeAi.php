<?php

namespace App\Application\TreeMerge\Ai;

use App\Entity\TreeMergeDraft;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Target;

#[AsAlias(TreeMergeAiInterface::class, when: ['dev', 'prod'])]
final readonly class SymfonyTreeMergeAi implements TreeMergeAiInterface
{
    public function __construct(#[Target('treeMerge')] private AgentInterface $agent)
    {
    }

    public function respond(TreeMergeDraft $draft, string $attemptId): string
    {
        return $this->agent->call(MergePrompt::input($draft))->asText();
    }
}
