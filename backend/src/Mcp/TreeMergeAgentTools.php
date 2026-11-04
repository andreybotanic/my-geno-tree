<?php

namespace App\Mcp;

use App\Api\ApiException;
use App\Application\TreeMerge\TreeMergeAgentCommands;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;

/** Internal server only: the scoped capability cannot create chats, apply changes or use the public server. */
final readonly class TreeMergeAgentTools
{
    public function __construct(private TreeMergeAgentCommands $commands)
    {
    }

    /** @param array<string, mixed> $proposal */
    #[McpTool(name: 'prepare_tree_merge_proposal', description: 'Validate and stage the complete explicit proposal; returns proposalId and exact new/existing counts. Does not apply tree changes. Correct rejected arguments yourself using error details.', annotations: new ToolAnnotations(destructiveHint: false, openWorldHint: false))]
    public function prepare(string $treeId, #[Schema(definition: TreeMergeToolSchema::PROPOSAL)] array $proposal): CallToolResult
    {
        return $this->execute(fn () => $this->commands->prepare($treeId, $proposal));
    }

    #[McpTool(name: 'reply_to_tree_merge', description: 'Send the user-visible reply VERBATIM and end this attempt. ONE short sentence, at most 350 characters; for a proposal include its exact new-person count and the reason for any omissions. Pass proposalId to submit a staged proposal for UI review; needsClarification=true asks a necessary question. Never applies changes.', annotations: new ToolAnnotations(destructiveHint: false, openWorldHint: false))]
    public function reply(string $treeId, #[Schema(minLength: 1, maxLength: 350)] string $reply, ?string $proposalId = null, bool $needsClarification = false): CallToolResult
    {
        return $this->execute(fn () => $this->commands->reply($treeId, $reply, $proposalId, $needsClarification));
    }

    /** @param \Closure(): array<string, mixed> $command */
    private function execute(\Closure $command): CallToolResult
    {
        try {
            $result = $command();

            return new CallToolResult([new TextContent(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))], structuredContent: $result);
        } catch (ApiException $error) {
            return CallToolResult::error([new TextContent(json_encode(['code' => $error->getErrorCode(),
                'fields'                                                      => $error->getFields(), 'details' => $error->getDetails()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))]);
        }
    }
}
