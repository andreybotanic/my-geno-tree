<?php

namespace App\Application\TreeMerge\Ai;

use App\Entity\TreeMergeDraft;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ConfiguredTreeMergeAi implements TreeMergeAiInterface
{
    public function __construct(private SymfonyTreeMergeAi $ollama, private OpenCodeTreeMergeAi $openCode,
        #[Autowire(env: 'TREE_MERGE_AI')] private string $provider,
        #[Autowire(param: 'kernel.environment')] private string $environment)
    {
    }

    public function respond(TreeMergeDraft $draft, string $attemptId, ?MergeAiRepair $repair = null): MergeAiResult
    {
        return match ($this->provider) {
            'ollama'   => $this->ollama->respond($draft, $attemptId, $repair),
            'opencode' => 'dev' === $this->environment ? $this->openCode->respond($draft, $attemptId, $repair)
                : throw new \RuntimeException('OPENCODE_IS_DEVELOPMENT_ONLY'),
            default => throw new \RuntimeException('TREE_MERGE_PROVIDER_INVALID'),
        };
    }
}
