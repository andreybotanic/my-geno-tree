<?php

namespace App\Controller\Api\Tree;

use App\Api\ApiException;
use App\Api\FamilyTreeViewFactory;
use App\Api\RequestBodyMapper;
use App\Application\Chat\ChatInterface;
use App\Application\Tree\DeleteTreeService;
use App\Application\Tree\RenameTreeService;
use App\Application\Tree\TreeContextService;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Request\Tree\TreeNameRequest;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class TreeItemController
{
    public function __construct(
        private RequestBodyMapper $requestBody,
        private RenameTreeService $renameTree,
        private DeleteTreeService $deleteTree,
        private FamilyTreeViewFactory $views,
        private TreeVersion $versions,
    ) {
    }

    #[Route(
        '/api/v1/trees/{treeId}',
        name: 'api_trees_rename',
        methods: ['PATCH'],
    )]
    #[IsGranted(
        FamilyTreeVoter::EDIT,
        subject: 'tree',
        statusCode: JsonResponse::HTTP_NOT_FOUND,
    )]
    public function rename(
        Request $request,
        #[MapEntity(id: 'treeId')]
        FamilyTree $tree,
    ): JsonResponse {
        $input = $this->requestBody->map($request, TreeNameRequest::class);
        $this->renameTree->rename(
            $tree,
            $input->name,
            $request->headers->get('If-Match'),
        );
        $response = new JsonResponse($this->views->create($tree));
        $response->headers->set('ETag', $this->versions->etag($tree));

        return $response;
    }

    #[Route(
        '/api/v1/trees/{treeId}',
        name: 'api_trees_delete',
        methods: ['DELETE'],
    )]
    #[IsGranted(
        FamilyTreeVoter::EDIT,
        subject: 'tree',
        statusCode: JsonResponse::HTTP_NOT_FOUND,
    )]
    public function delete(
        Request $request,
        #[MapEntity(id: 'treeId')]
        FamilyTree $tree,
    ): Response {
        $this->deleteTree->delete(
            $tree,
            $request->headers->get('If-Match'),
        );

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/v1/trees/{treeId}/chat', name: 'api_tree_chat_current', methods: ['GET'])]
    #[IsGranted(FamilyTreeVoter::EDIT, subject: 'tree', statusCode: 404)]
    public function currentChat(#[MapEntity(id: 'treeId')] FamilyTree $tree, ChatInterface $chat): JsonResponse
    {
        $conversation = $chat->current('tree:' . $tree->getId()->toRfc4122());

        return new JsonResponse(
            [
                'conversation' => null === $conversation ? null : $chat->snapshot($conversation),
                'tokenBudget'  => $chat->budget($tree->getOwner()->getId()),
            ]
        );
    }

    #[Route('/api/v1/trees/{treeId}/chat', name: 'api_tree_chat_create', methods: ['POST'])]
    #[IsGranted(FamilyTreeVoter::EDIT, subject: 'tree', statusCode: 404)]
    public function createChat(
        #[MapEntity(id: 'treeId')]
        FamilyTree $tree,
        Request $request,
        ChatInterface $chat,
        TreeContextService $context,
    ): JsonResponse {
        $payload = $this->requestBody->decodeObject($request);
        if (['referencePersonId'] !== array_keys($payload) || (null !== $payload['referencePersonId'] && !is_string($payload['referencePersonId']))) {
            throw new ApiException(422, 'CHAT_INVALID');
        }
        $context->context($tree->getId()->toRfc4122(), $payload['referencePersonId']);
        $conversation = $chat->open(
            'tree:' . $tree->getId()->toRfc4122(),
            'tree',
            [
                'treeId'            => $tree->getId()->toRfc4122(),
                'referencePersonId' => $payload['referencePersonId'],
            ]
        );

        return new JsonResponse($chat->snapshot($conversation), 201);
    }
}
