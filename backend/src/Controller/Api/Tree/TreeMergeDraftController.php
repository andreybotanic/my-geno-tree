<?php

namespace App\Controller\Api\Tree;

use App\Api\RequestBodyMapper;
use App\Api\TreeMergeDraftViewFactory;
use App\Application\TreeMerge\TreeMergeDraftService;
use App\Entity\FamilyTree;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/trees/{treeId}/merges')]
#[IsGranted(FamilyTreeVoter::EDIT, subject: 'tree', statusCode: 404)]
final readonly class TreeMergeDraftController
{
    public function __construct(
        private TreeMergeDraftService $drafts,
        private TreeMergeDraftViewFactory $views,
        private RequestBodyMapper $body,
    ) {
    }

    #[Route('', name: 'api_tree_merge_list', methods: ['GET'])]
    public function list(#[MapEntity(id: 'treeId')] FamilyTree $tree): JsonResponse
    {
        return new JsonResponse(['items' => array_map($this->views->create(...), $this->drafts->recent($tree))]);
    }

    #[Route('', name: 'api_tree_merge_create', methods: ['POST'])]
    public function create(#[MapEntity(id: 'treeId')] FamilyTree $tree, Request $request): JsonResponse
    {
        return new JsonResponse($this->views->create($this->drafts->create($tree, $this->body->decodeObject($request))), 201);
    }

    #[Route('/{mergeId}', name: 'api_tree_merge_get', methods: ['GET'])]
    public function get(#[MapEntity(id: 'treeId')] FamilyTree $tree, string $mergeId): JsonResponse
    {
        return new JsonResponse($this->views->create($this->drafts->get($tree, $mergeId)));
    }

    #[Route('/{mergeId}/messages', name: 'api_tree_merge_message', methods: ['POST'])]
    public function message(#[MapEntity(id: 'treeId')] FamilyTree $tree, string $mergeId, Request $request): JsonResponse
    {
        $draft = $this->drafts->get($tree, $mergeId);
        $this->drafts->append($draft, $this->body->decodeObject($request));

        return new JsonResponse($this->views->create($draft));
    }

    #[Route('/{mergeId}/cancel', name: 'api_tree_merge_cancel', methods: ['POST'])]
    public function cancel(#[MapEntity(id: 'treeId')] FamilyTree $tree, string $mergeId, Request $request): JsonResponse
    {
        $draft = $this->drafts->get($tree, $mergeId);
        $this->drafts->cancel($draft, $this->body->decodeObject($request)['version'] ?? null);

        return new JsonResponse($this->views->create($draft));
    }
}
