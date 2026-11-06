<?php

namespace App\EventListener;

use App\Api\ApiException;
use App\Application\Chat\GuardedMessageStore;
use App\Application\Chat\MercureChatTransport;
use App\Model\Conversation;
use App\Security\ChatExecution;
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
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** Framework integration: bind metadata, authorize every round/tool, and meter every model response. */
#[AsInputProcessor(priority: 1000)]
#[AsOutputProcessor]
final readonly class ChatAgentHooks implements InputProcessorInterface, OutputProcessorInterface
{
    public function __construct(
        private GuardedMessageStore $store,
        private MercureChatTransport $events,
        private RequestStack $requests,
        #[Target('chat_tokens')]
        private RateLimiterFactoryInterface $tokens,
    ) {
    }

    public function processInput(Input $input): void
    {
        if ($this->executing()) {
            $input->getMessageBag()->getMetadata()->add(Conversation::METADATA, $this->store->execution()->conversation);
        }
    }

    public function processOutput(Output $output): void
    {
        if ($this->executing()) {
            $this->store->execution();
        }
    }

    #[AsEventListener(priority: 10)]
    public function beforeModel(InvocationEvent $event): void
    {
        if ($this->executing()) {
            $state = $this->store->execution()->conversation;
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
            $this->store->execution();
        } catch (ApiException $error) {
            $event->setResult(new ToolResult($event->getToolCall(), ['error' => ['code' => $error->getErrorCode()]]));
        }
    }

    #[AsEventListener]
    public function onResult(ResultEvent $event): void
    {
        $state = $this->requests->getMainRequest()?->attributes->get(Conversation::class);
        if (!$state instanceof Conversation) {
            return;
        }
        $deferred = $event->getDeferredResult();
        $deferred->onConvert(
            function (ResultInterface $result) use ($state): ResultInterface {
                $this->record($state, $result->getMetadata()->get('token_usage'));

                return $result;
            }
        );
        $deferred->onError(
            function () use ($state, $deferred, $event): void {
                $this->record($state, $deferred->getResultConverter()->getTokenUsageExtractor()?->extract($deferred->getRawResult(), $event->getOptions()));
            }
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
