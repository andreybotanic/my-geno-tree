<?php

namespace App\Application\TreeMerge\Ai;

use App\Entity\TreeMergeDraft;
use Symfony\AI\Platform\Event\InvocationEvent;
use Symfony\AI\Platform\Event\ResultEvent;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;

/** Counts every model round, independently of the configured agent and platform. */
final readonly class MergeModelUsageListener
{
    public function __construct(private MergeTokenBudget $budget, private RequestStack $requests)
    {
    }

    #[AsEventListener]
    public function beforeInvocation(InvocationEvent $event): void
    {
        $draft = $this->requests->getCurrentRequest()?->attributes->get(TreeMergeDraft::class);
        if ($draft instanceof TreeMergeDraft) {
            $this->budget->assertAvailable($draft);
        }
    }

    #[AsEventListener]
    public function onResult(ResultEvent $event): void
    {
        $draft = $this->requests->getCurrentRequest()?->attributes->get(TreeMergeDraft::class);
        if (!$draft instanceof TreeMergeDraft) {
            return;
        }
        $deferred = $event->getDeferredResult();
        $deferred->onConvert(function (ResultInterface $result) use ($draft): ResultInterface {
            $this->record($draft, $result->getMetadata()->get('token_usage'));

            return $result;
        });
        $deferred->onError(function () use ($draft, $deferred, $event): void {
            $this->record($draft, $deferred->getResultConverter()->getTokenUsageExtractor()?->extract($deferred->getRawResult(), $event->getOptions()));
        });
    }

    private function record(TreeMergeDraft $draft, mixed $usage): void
    {
        if ($usage instanceof TokenUsageInterface) {
            $this->budget->record($draft, $usage->getPromptTokens() ?? 0, $usage->getCompletionTokens() ?? 0, $usage->getTotalTokens());
        }
    }
}
