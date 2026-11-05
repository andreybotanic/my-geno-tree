<?php

namespace App\Controller\Api\Tree;

use App\Api\ChatViewFactory;
use App\Api\RequestBodyMapper;
use App\Application\Chat\ChatTokenBudget;
use App\Application\TreeChat\TreeAssistant;
use App\Entity\FamilyTree;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/trees/{treeId}/chat')]
#[IsGranted(FamilyTreeVoter::EDIT, subject: 'tree', statusCode: 404)]
final readonly class TreeChatController
{
    public function __construct(private TreeAssistant $assistant, private ChatViewFactory $views, private ChatTokenBudget $budget, private RequestBodyMapper $body)
    {
    }

    #[Route('', name: 'api_tree_chat_current', methods: ['GET'])]
    public function current(#[MapEntity(id: 'treeId')] FamilyTree $tree): JsonResponse
    {
        $conversation = $this->assistant->current($tree);

        return new JsonResponse(['conversation' => null === $conversation ? null : $this->views->create($conversation),
            'tokenBudget'                       => $this->budget->remaining($tree->getOwner()->getId())]);
    }

    #[Route('', name: 'api_tree_chat_create', methods: ['POST'], defaults: ['_chat_event' => true])]
    public function create(#[MapEntity(id: 'treeId')] FamilyTree $tree, Request $request): JsonResponse
    {
        return new JsonResponse($this->views->create($this->assistant->create($tree, $this->body->decodeObject($request))), 201);
    }
}
