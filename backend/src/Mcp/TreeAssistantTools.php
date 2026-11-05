<?php

namespace App\Mcp;

use App\Api\ApiException;
use App\Api\ChatViewFactory;
use App\Application\Chat\Chat;
use App\Application\Chat\ConversationService;
use App\Application\Tree\TreeContextService;
use App\Application\TreeChat\TreeAssistant;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

final readonly class TreeAssistantTools
{
    public function __construct(private TreeContextService $context, private TreeAssistant $assistant, private Chat $chat,
        private ConversationService $conversation, private ChatViewFactory $views,
        #[Autowire(env: 'APP_URL')] private string $appUrl)
    {
    }

    /** @return array<string, mixed> */
    #[McpTool(name: 'prepare_tree_merge', description: 'Prepare a reviewable family branch from description (10000 characters maximum). People may be named directly; ambiguous names require clarification. Never applies changes.', annotations: new ToolAnnotations(destructiveHint: false))]
    public function prepare(string $treeId, string $description, ?string $referencePersonId = null): array
    {
        $tree = $this->context->tree($treeId, true);
        if ('' === trim($description) || mb_strlen($description) > Chat::MAX_INPUT_LENGTH) {
            throw new ApiException(422, 'TREE_MERGE_INVALID');
        }
        $draft = $this->assistant->create($tree, ['referencePersonId' => $referencePersonId]);
        $requestId = Uuid::v7()->toRfc4122();
        $draft = $this->conversation->append($draft, ['version' => $draft->version, 'requestId' => $requestId, 'content' => $description]);
        $this->conversation->process($draft, $draft->version, $requestId);

        return $this->get($treeId, $draft->id->toRfc4122());
    }

    /** @return array<string, mixed> */
    #[McpTool(name: 'get_tree_merge', description: 'Read a merge draft and its UI preview link. Confirmation and application are available only in the UI.', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function get(string $treeId, string $mergeId): array
    {
        $this->context->tree($treeId);
        $draft = $this->chat->get($mergeId);
        if (($draft->context['treeId'] ?? null) !== $treeId) {
            throw new ApiException(404, 'CHAT_NOT_FOUND');
        }

        return $this->views->create($draft) + ['previewUrl' => rtrim($this->appUrl, '/') . '/trees/' . $treeId . '/tree?chat=' . $mergeId];
    }
}
