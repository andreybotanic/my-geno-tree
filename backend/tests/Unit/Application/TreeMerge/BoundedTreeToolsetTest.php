<?php

namespace App\Tests\Unit\Application\TreeMerge;

use App\Application\TreeMerge\Ai\BoundedTreeToolset;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Bridge\Mcp\ToolsetInterface;

final class BoundedTreeToolsetTest extends TestCase
{
    public function testFiltersToolsCollectsObservedPeopleAndBoundsRequests(): void
    {
        $remote = $this->createStub(ToolsetInterface::class);
        $remote->method('getTools')->willReturn([Tool::fromArray(['name' => 'get_tree_context', 'inputSchema' => ['type' => 'object']]), Tool::fromArray(['name' => 'prepare_tree_merge', 'inputSchema' => ['type' => 'object']])]);
        $remote->method('callTool')->willReturn(new CallToolResult([new TextContent('{"people":[{"id":"person"}]}')]));
        $tools = new BoundedTreeToolset($remote, 'tree');
        self::assertSame('tree', $tools->getName());
        self::assertCount(1, $tools->getTools());
        for ($i = 0; $i < 8; ++$i) {
            $tools->callTool('get_tree_context', ['treeId' => 'tree']);
        }
        self::assertArrayHasKey('person', $tools->people);
        $this->expectExceptionMessage('TREE_CONTEXT_LIMIT');
        $tools->callTool('get_tree_context', ['treeId' => 'tree']);
    }

    public function testRejectsForeignTreeWithoutCallingRemote(): void
    {
        $remote = $this->createMock(ToolsetInterface::class);
        $remote->expects($this->never())->method('callTool');
        $this->expectExceptionMessage('TREE_CONTEXT_LIMIT');
        (new BoundedTreeToolset($remote, 'tree'))->callTool('get_person_context', ['treeId' => 'other']);
    }

    public function testRejectsOversizedContext(): void
    {
        $remote = $this->createStub(ToolsetInterface::class);
        $remote->method('callTool')->willReturn(new CallToolResult([new TextContent(str_repeat('a', 60001))]));
        $this->expectExceptionMessage('TREE_CONTEXT_UNAVAILABLE');
        (new BoundedTreeToolset($remote, 'tree'))->callTool('get_tree_context', ['treeId' => 'tree']);
    }

    public function testAcceptsStructuredContentAndRejectsToolErrors(): void
    {
        $remote = $this->createStub(ToolsetInterface::class);
        $remote->method('callTool')->willReturnOnConsecutiveCalls(
            new CallToolResult([], structuredContent: ['people' => [['id' => 'p']]]),
            new CallToolResult([], isError: true),
        );
        $tools = new BoundedTreeToolset($remote, 'tree');
        $tools->callTool('get_person_context', ['treeId' => 'tree']);
        self::assertArrayHasKey('p', $tools->people);
        $this->expectExceptionMessage('TREE_CONTEXT_UNAVAILABLE');
        $tools->callTool('get_person_context', ['treeId' => 'tree']);
    }
}
