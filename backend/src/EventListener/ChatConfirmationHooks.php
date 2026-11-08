<?php

namespace App\EventListener;

use App\Api\ApiException;
use App\Application\Chat\ChatEventPublisherInterface;
use App\Application\Chat\GuardedMessageStore;
use App\Application\Tree\TreeActionService;
use App\Model\ToolConfirmation;
use App\Security\ChatExecution;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Event\ToolCallsExecuted;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class ChatConfirmationHooks
{
    public function __construct(
        private GuardedMessageStore $store,
        private TreeActionService $actions,
        private ChatEventPublisherInterface $events,
        private RequestStack $requests,
        private ClockInterface $clock,
    ) {
    }

    #[AsEventListener(priority: -10)]
    public function beforeTool(ToolCallRequested $event): void
    {
        if (!$this->executing()) {
            return;
        }
        $call = $event->getToolCall();
        if (!in_array($call->getName(), TreeActionService::NAMES, true)) {
            return;
        }
        $execution = $this->store->execution();
        $state = $execution->conversation;
        if (($call->getArguments()['treeId'] ?? null) !== ($state->context['treeId'] ?? null)) {
            $event->setResult(new ToolResult($call, ['error' => ['code' => 'TREE_NOT_FOUND']]));

            return;
        }
        if (!TreeActionService::needsConfirmation($call->getName()) || $state->autoApprovePersonActions) {
            return;
        }
        try {
            $summary = $this->actions->describe($call->getName(), $call->getArguments());
        } catch (ApiException $error) {
            $event->setResult(
                new ToolResult(
                    $call,
                    [
                        'error' => [
                            'code'    => $error->getErrorCode(),
                            'fields'  => $error->getFields(),
                            'details' => $error->getDetails(),
                        ],
                    ],
                )
            );

            return;
        }
        $confirmation = new ToolConfirmation(
            $call,
            $state->attemptRequestId ?? '',
            $summary,
            $this->clock->now(),
        );
        $state->confirmations[] = $confirmation;
        $execution->paused = true;
        $this->store->persist($state);
        $event->setResult(new ToolResult($call, ['state' => 'awaiting_confirmation', 'id' => $confirmation->id]));
    }

    #[AsEventListener]
    public function afterRound(ToolCallsExecuted $event): void
    {
        if ($this->executing() && $this->store->execution()->paused) {
            $event->setResult(new TextResult(''));
        }
    }

    #[AsEventListener]
    public function applied(ToolCallSucceeded $event): void
    {
        if (!$this->executing()) {
            return;
        }
        $call = $event->getResult()->getToolCall();
        $result = $event->getResult()->getResult();
        if (in_array($call->getName(), TreeActionService::NAMES, true) && is_array($result)
            && isset($result['etag']) && !isset($result['error'])) {
            /** @var array<string, mixed> $applied */
            $applied = $result;
            $this->events->toolApplied(
                $this->store->execution()->conversation,
                $call->getName(),
                $call->getId(),
                $applied,
            );
        }
    }

    private function executing(): bool
    {
        return $this->requests->getMainRequest()?->attributes->get(ChatExecution::class) instanceof ChatExecution;
    }
}
