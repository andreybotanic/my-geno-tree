<?php

namespace App\Application\Chat\Ai;

use App\Application\Chat\ChatTokenBudget;
use App\Model\Conversation;
use Symfony\AI\Platform\Event\InvocationEvent;
use Symfony\AI\Platform\Event\ResultEvent;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;

/** Counts every model round, independently of the configured agent and platform. */
final readonly class ChatModelUsageListener
{
    public function __construct(private ChatTokenBudget $budget, private RequestStack $requests)
    {
    }

    #[AsEventListener]
    public function beforeInvocation(InvocationEvent $event): void
    {
        $conversation = $this->requests->getCurrentRequest()?->attributes->get(Conversation::class);
        if ($conversation instanceof Conversation) {
            $this->budget->assertAvailable($conversation);
        }
    }

    #[AsEventListener]
    public function onResult(ResultEvent $event): void
    {
        $conversation = $this->requests->getCurrentRequest()?->attributes->get(Conversation::class);
        if (!$conversation instanceof Conversation) {
            return;
        }
        $deferred = $event->getDeferredResult();
        $deferred->onConvert(function (ResultInterface $result) use ($conversation): ResultInterface {
            $this->record($conversation, $result->getMetadata()->get('token_usage'));

            return $result;
        });
        $deferred->onError(function () use ($conversation, $deferred, $event): void {
            $this->record($conversation, $deferred->getResultConverter()->getTokenUsageExtractor()?->extract($deferred->getRawResult(), $event->getOptions()));
        });
    }

    private function record(Conversation $conversation, mixed $usage): void
    {
        if ($usage instanceof TokenUsageInterface) {
            $this->budget->record($conversation, $usage->getPromptTokens() ?? 0, $usage->getCompletionTokens() ?? 0, $usage->getTotalTokens());
        }
    }
}
