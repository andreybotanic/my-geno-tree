<?php

namespace App\Application\TreeMerge\Ai;

use Mcp\Schema\Result\CallToolResult;
use Symfony\AI\Agent\Bridge\Mcp\ToolsetInterface;

final class BoundedTreeToolset implements ToolsetInterface
{
    public const array TOOLS = ['get_tree_context', 'find_people', 'get_person_context', 'prepare_tree_merge_proposal', 'reply_to_tree_merge'];
    private int $calls = 0;
    private int $bytes = 0;

    public function __construct(private readonly ToolsetInterface $remote, private readonly string $treeId)
    {
    }

    public function getName(): string
    {
        return 'tree';
    }

    public function getTools(): array
    {
        return array_values(array_filter($this->remote->getTools(), static fn ($tool) => in_array($tool->name, self::TOOLS, true)));
    }

    public function callTool(string $name, array $arguments = []): CallToolResult
    {
        if (!in_array($name, self::TOOLS, true) || ($arguments['treeId'] ?? null) !== $this->treeId || ++$this->calls > 16) {
            throw new \RuntimeException('TREE_CONTEXT_LIMIT');
        }
        $result = $this->remote->callTool($name, $arguments);
        $this->bytes += strlen(json_encode($result, JSON_THROW_ON_ERROR));
        if ($this->bytes > 60000) {
            throw new \RuntimeException('TREE_CONTEXT_LIMIT');
        }

        // Validation errors go back to the agent, which can correct its MCP arguments.
        return $result;
    }
}
