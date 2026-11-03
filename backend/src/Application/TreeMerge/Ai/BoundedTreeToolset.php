<?php

namespace App\Application\TreeMerge\Ai;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\AI\Agent\Bridge\Mcp\ToolsetInterface;

/** @phpstan-import-type ContextPerson from MergeAiResult */
final class BoundedTreeToolset implements ToolsetInterface
{
    private const array TOOLS = ['get_tree_context', 'find_people', 'get_person_context'];
    private int $calls = 0;
    private int $bytes = 0;
    /** @var array<string, ContextPerson> */
    public private(set) array $people = [];
    /** @var list<array<array-key, mixed>> */
    public private(set) array $contexts = [];

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
        if (!in_array($name, self::TOOLS, true) || ($arguments['treeId'] ?? null) !== $this->treeId || ++$this->calls > 8) {
            throw new \RuntimeException('TREE_CONTEXT_LIMIT');
        }
        $result = $this->remote->callTool($name, $arguments);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        $this->bytes += strlen($encoded);
        if ($this->bytes > 60000 || $result->isError) {
            throw new \RuntimeException('TREE_CONTEXT_UNAVAILABLE');
        }
        $data = $result->structuredContent;
        if (null === $data) {
            foreach ($result->content as $content) {
                if ($content instanceof TextContent && is_string($content->text)) {
                    $data = json_decode($content->text, true, flags: JSON_THROW_ON_ERROR);
                    break;
                }
            }
        }
        if (is_array($data)) {
            $this->contexts[] = $data;
        }
        foreach (is_array($data) && is_array($data['people'] ?? null) ? $data['people'] : [] as $person) {
            if (is_array($person) && is_string($person['id'] ?? null)) {
                /** @var ContextPerson $observed */
                $observed = $person;
                $this->people[$observed['id']] = $observed;
            }
        }

        return $result;
    }
}
