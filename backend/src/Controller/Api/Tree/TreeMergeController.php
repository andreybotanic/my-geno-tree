<?php

namespace App\Controller\Api\Tree;

use App\Api\ChatViewFactory;
use App\Api\RequestBodyMapper;
use App\Application\TreeChat\TreeAssistant;
use App\Entity\FamilyTree;
use App\Model\Conversation;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/trees/{treeId}/merge-proposals/{proposalId}')]
#[IsGranted(FamilyTreeVoter::EDIT, subject: 'tree', statusCode: 404)]
final readonly class TreeMergeController
{
    public function __construct(private TreeAssistant $assistant, private RequestBodyMapper $body, private ChatViewFactory $views)
    {
    }

    #[Route('', name: 'api_tree_merge_discard', methods: ['DELETE'], defaults: ['_chat_event' => true])]
    public function discard(#[MapEntity(id: 'treeId')] FamilyTree $tree, string $proposalId, Request $request): JsonResponse
    {
        return $this->action($tree, $proposalId, $request, 'discard');
    }

    #[Route('', name: 'api_tree_merge_revise', methods: ['PUT'], defaults: ['_chat_event' => true])]
    public function revise(#[MapEntity(id: 'treeId')] FamilyTree $tree, string $proposalId, Request $request): JsonResponse
    {
        return $this->action($tree, $proposalId, $request, 'revise');
    }

    #[Route('/revalidate', name: 'api_tree_merge_revalidate', methods: ['POST'], defaults: ['_chat_event' => true])]
    public function revalidate(#[MapEntity(id: 'treeId')] FamilyTree $tree, string $proposalId, Request $request): JsonResponse
    {
        return $this->action($tree, $proposalId, $request, 'revalidate');
    }

    #[Route('/apply', name: 'api_tree_merge_apply', methods: ['POST'])]
    public function apply(#[MapEntity(id: 'treeId')] FamilyTree $tree, string $proposalId, Request $request): JsonResponse
    {
        return $this->action($tree, $proposalId, $request, 'apply');
    }

    private function action(FamilyTree $tree, string $id, Request $request, string $action): JsonResponse
    {
        $result = $this->assistant->proposal($tree, $id, $action, $this->body->decodeObject($request), $request->headers->get('If-Match'));

        return new JsonResponse($result instanceof Conversation ? $this->views->create($result) : $result);
    }
}
