<?php

namespace App\Application\Chat;

use App\Application\Tree\TreeActionService;
use Symfony\AI\Agent\Toolbox\SequentialToolExecutor;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolExecutorInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** A mutation's result must be known before the model can prepare its successor. */
final readonly class ChatToolExecutor implements ToolExecutorInterface
{
    public function __construct(#[Autowire(service: 'ai.toolbox.tree')] private ToolboxInterface $toolbox)
    {
    }

    public function execute(array $toolCalls): \Generator
    {
        if (count($toolCalls) > 1 && array_any(
            $toolCalls,
            static fn ($call) => in_array($call->getName(), TreeActionService::NAMES, true),
        )) {
            // Keep a generator even when rejecting the entire round before its first side effect.
            yield from [];

            return array_map(
                static fn ($call) => new ToolResult($call, ['error' => ['code' => 'TOOL_CALL_SEQUENCE_REQUIRED']]),
                $toolCalls,
            );
        }

        return yield from new SequentialToolExecutor($this->toolbox)->execute($toolCalls);
    }
}
