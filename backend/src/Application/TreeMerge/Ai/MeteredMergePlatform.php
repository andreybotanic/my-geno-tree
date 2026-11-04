<?php

namespace App\Application\TreeMerge\Ai;

use App\Entity\TreeMergeDraft;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

final readonly class MeteredMergePlatform implements PlatformInterface
{
    public function __construct(private PlatformInterface $platform, private MergeTokenBudget $budget, private TreeMergeDraft $draft)
    {
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $this->budget->assertAvailable($this->draft);
        $deferred = $this->platform->invoke($model, $input, $options);
        $deferred->onConvert(function (ResultInterface $result): ResultInterface {
            $this->record($result->getMetadata()->get('token_usage'));

            return $result;
        });
        $deferred->onError(function () use ($deferred, $options): void {
            $this->record($deferred->getResultConverter()->getTokenUsageExtractor()?->extract($deferred->getRawResult(), $options));
        });

        return $deferred;
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->platform->getModelCatalog();
    }

    private function record(mixed $usage): void
    {
        if ($usage instanceof TokenUsageInterface) {
            $this->budget->record($this->draft, $usage->getPromptTokens() ?? 0, $usage->getCompletionTokens() ?? 0, $usage->getTotalTokens());
        }
    }
}
