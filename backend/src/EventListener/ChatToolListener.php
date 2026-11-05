<?php

namespace App\EventListener;

use App\Api\ApiException;
use App\Application\Chat\Chat;
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
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;

/** Generic lifecycle guard, shared by all configured chat agents and their tools. */
#[AsInputProcessor(priority: 1000)]
#[AsOutputProcessor]
final readonly class ChatToolListener implements InputProcessorInterface, OutputProcessorInterface
{
    public function __construct(private Chat $chat, private RequestStack $requests)
    {
    }

    public function processInput(Input $input): void
    {
        if ($this->executing()) {
            $input->getMessageBag()->getMetadata()->add(Chat::METADATA, $this->chat->active());
        }
    }

    public function processOutput(Output $output): void
    {
        if ($this->executing()) {
            $this->chat->active();
        }
    }

    #[AsEventListener(priority: 10)]
    public function beforeModel(InvocationEvent $event): void
    {
        if ($this->executing()) {
            $this->chat->active();
        }
    }

    #[AsEventListener]
    public function beforeTool(ToolCallRequested $event): void
    {
        if (!$this->executing()) {
            return;
        }
        try {
            $this->chat->active();
        } catch (ApiException $error) {
            $event->setResult(new ToolResult($event->getToolCall(), ['error' => ['code' => $error->getErrorCode()]]));
        }
    }

    private function executing(): bool
    {
        return $this->requests->getMainRequest()?->attributes->get(ChatExecution::class) instanceof ChatExecution;
    }
}
