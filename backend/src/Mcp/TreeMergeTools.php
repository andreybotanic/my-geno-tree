<?php

namespace App\Mcp;

use App\Api\ApiException;
use App\Api\TreeMergeDraftViewFactory;
use App\Application\TreeMerge\TreeContextService;
use App\Application\TreeMerge\TreeMergeConversationService;
use App\Application\TreeMerge\TreeMergeDraftService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

final readonly class TreeMergeTools
{
    public function __construct(private TreeContextService $context, private TreeMergeDraftService $drafts,
        private TreeMergeConversationService $conversation, private TreeMergeDraftViewFactory $views,
        #[Autowire(env: 'APP_URL')] private string $appUrl)
    {
    }

    /** @return array<string, mixed> */
    #[McpTool(name: 'prepare_tree_merge', description: 'Prepare a reviewable family branch from description (10000 characters maximum). People may be named directly; ambiguous names require clarification. Never applies changes.', annotations: new ToolAnnotations(destructiveHint: false))]
    public function prepare(string $treeId, string $description, ?string $referencePersonId = null): array
    {
        $tree = $this->context->tree($treeId, true);
        if ('' === trim($description) || mb_strlen($description) > TreeMergeDraftService::MAX_SOURCE_LENGTH) {
            throw new ApiException(422, 'TREE_MERGE_INVALID');
        }
        $draft = $this->drafts->create($tree, ['referencePersonId' => $referencePersonId]);
        $requestId = Uuid::v7()->toRfc4122();
        $this->drafts->append($draft, ['version' => $draft->version, 'requestId' => $requestId, 'content' => $description]);
        $this->conversation->process($draft, $draft->version, $requestId);

        return $this->get($treeId, $draft->id->toRfc4122());
    }

    /** @return array<string, mixed> */
    #[McpTool(name: 'get_tree_merge', description: 'Read a merge draft and its UI preview link. Confirmation and application are available only in the UI.', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function get(string $treeId, string $mergeId): array
    {
        $draft = $this->drafts->get($this->context->tree($treeId), $mergeId);

        return $this->views->create($draft) + ['previewUrl' => rtrim($this->appUrl, '/') . '/trees/' . $treeId . '/tree?merge=' . $mergeId];
    }
}
