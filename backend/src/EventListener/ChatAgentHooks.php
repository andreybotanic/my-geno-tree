<?php

namespace App\EventListener;

use App\Api\ApiException;
use App\Application\Chat\ChatEventPublisherInterface;
use App\Application\Chat\GuardedMessageStore;
use App\Application\Chat\Moderation\ChatModeration;
use App\Model\Conversation;
use App\Security\ChatExecution;
use App\Tool\TreeReadTools;
use Symfony\AI\Agent\Attribute\AsInputProcessor;
use Symfony\AI\Agent\Attribute\AsOutputProcessor;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;
use Symfony\AI\Agent\Output;
use Symfony\AI\Agent\OutputProcessorInterface;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Event\InvocationEvent;
use Symfony\AI\Platform\Event\ResultEvent;
use Symfony\AI\Platform\Exception\MaxOutputTokensException;
use Symfony\AI\Platform\FinishReason\FinishReason;
use Symfony\AI\Platform\FinishReason\FinishReasonCase;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\AbstractStreamListener;
use Symfony\AI\Platform\Result\Stream\DeltaEvent;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** Framework integration: bind metadata, authorize every round/tool, and meter every model response. */
#[AsInputProcessor(priority: 1000)]
#[AsOutputProcessor]
final class ChatAgentHooks extends AbstractStreamListener implements InputProcessorInterface, OutputProcessorInterface
{
    public function __construct(
        private readonly GuardedMessageStore $store,
        private readonly ChatEventPublisherInterface $events,
        private readonly RequestStack $requests,
        #[Target('chat_tokens')]
        private readonly RateLimiterFactoryInterface $tokens,
        private readonly ChatModeration $moderation,
    ) {
    }

    public function processInput(Input $input): void
    {
        if ($this->executing()) {
            $input->getMessageBag()->getMetadata()->add(
                Conversation::METADATA,
                $this->store->execution()->conversation,
            );
        }
    }

    public function processOutput(Output $output): void
    {
        if ($this->executing()) {
            $this->store->execution();
        }
        $reason = $output->getResult()->getMetadata()->get('finish_reason');
        if ($reason instanceof FinishReason && $reason->is(FinishReasonCase::LENGTH)) {
            throw new MaxOutputTokensException('Model response was truncated.');
        }
    }

    #[AsEventListener(priority: 10)]
    public function beforeModel(InvocationEvent $event): void
    {
        if ($this->executing()) {
            $state = $this->store->execution()->conversation;
            $this->moderation->assertCanInteract($state->userId);
            if ($this->tokens->create($state->userId->toRfc4122())->consume(0)->getRemainingTokens() <= 0) {
                throw new ApiException(429, 'CHAT_TOKEN_LIMIT');
            }
        }
    }

    #[AsEventListener]
    public function beforeTool(ToolCallRequested $event): void
    {
        if (!$this->executing()) {
            return;
        }
        try {
            $execution = $this->store->execution();
            $this->moderation->assertCanInteract($execution->conversation->userId);
            if (TreeReadTools::class === $event->getDefinition()->getReference()->getClass()) {
                if ('places' !== $event->getDefinition()->getReference()->getMethod()
                    && ($event->getToolCall()->getArguments()['treeId'] ?? null)
                    !== ($execution->conversation->context['treeId'] ?? null)) {
                    throw new ApiException(404, 'TREE_NOT_FOUND');
                }
            }
        } catch (ApiException $error) {
            $event->setResult(new ToolResult($event->getToolCall(), ['error' => ['code' => $error->getErrorCode()]]));
        }
    }

    public function onDelta(DeltaEvent $event): void
    {
        $state = $this->requests->getMainRequest()?->attributes->get(Conversation::class);
        $usage = $event->getDelta();
        if ($state instanceof Conversation && $usage instanceof TokenUsageInterface) {
            // Debit usage as it arrives, including streams that later fail or are cancelled.
            $this->record($state, $usage);
        }
    }

    #[AsEventListener(priority: -100)]
    public function onResult(ResultEvent $event): void
    {
        $state = $this->requests->getMainRequest()?->attributes->get(Conversation::class);
        if (!$state instanceof Conversation) {
            return;
        }
        $deferred = $event->getDeferredResult();
        $deferred->onConvert(
            function (ResultInterface $result) use ($state): ResultInterface {
                if ($result instanceof StreamResult) {
                    $result->addListener($this);
                } else {
                    $this->record($state, $result->getMetadata()->get('token_usage'));
                }

                return $result;
            }
        );
        $deferred->onError(
            function () use ($state, $deferred, $event): void {
                if ($event->getOptions()['stream'] ?? false) {
                    return;
                }
                $this->record(
                    $state,
                    $deferred->getResultConverter()->getTokenUsageExtractor()?->extract(
                        $deferred->getRawResult(),
                        $event->getOptions(),
                    ),
                );
            },
        );
    }

    private function record(Conversation $state, mixed $usage): void
    {
        if (!$usage instanceof TokenUsageInterface) {
            return;
        }
        $input = max(0, $usage->getPromptTokens() ?? 0);
        $output = max(0, $usage->getCompletionTokens() ?? 0);
        $total = max($input + $output, $usage->getTotalTokens() ?? 0);
        $limiter = $this->tokens->create($state->userId->toRfc4122());
        $limit = $limiter->consume(0)->getLimit();
        for ($remaining = $total; $remaining > 0; $remaining -= $chunk) {
            $chunk = min($remaining, $limit);
            $limiter->reserve($chunk);
        }
        try {
            $this->store->synchronized(
                $state->contextKey,
                function () use ($state, $input, $output, $total): void {
                    $this->store->execution();
                    $state->usage['input'] += $input;
                    $state->usage['output'] += $output;
                    $state->usage['total'] += $total;
                    $this->store->persist($state);
                    $this->events->progress($state);
                }
            );
        } catch (ApiException) {
            // A stopped, removed or revoked chat stays unchanged; spent tokens were still counted.
        }
    }

    private function executing(): bool
    {
        return $this->requests->getMainRequest()?->attributes->get(ChatExecution::class) instanceof ChatExecution;
    }
}
