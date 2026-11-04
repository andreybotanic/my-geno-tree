<?php

namespace App\Controller\Api\Tree;

use App\Api\ApiException;
use App\Api\RequestBodyMapper;
use App\Api\TreeMergeDraftViewFactory;
use App\Application\TreeMerge\Ai\MergeTokenBudget;
use App\Application\TreeMerge\TreeMergeConversationService;
use App\Application\TreeMerge\TreeMergeDraftService;
use App\Application\TreeMerge\TreeMergeProposalService;
use App\Application\TreeMerge\TreeMergeSubscription;
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
        private TreeMergeProposalService $proposals,
        private TreeMergeConversationService $conversation,
        private TreeMergeDraftViewFactory $views,
        private RequestBodyMapper $body,
        private MergeTokenBudget $budget,
    ) {
    }

    #[Route('', name: 'api_tree_merge_list', methods: ['GET'])]
    public function list(#[MapEntity(id: 'treeId')] FamilyTree $tree): JsonResponse
    {
        return new JsonResponse(['items' => array_map($this->views->create(...), $this->drafts->recent($tree)),
            'tokenBudget'                => $this->budget->remaining($tree->getOwner())]);
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
        $this->drafts->append($draft, $this->body->decodeObject($request), false);

        return new JsonResponse($this->views->create($draft));
    }

    #[Route('/{mergeId}/events', name: 'api_tree_merge_events', methods: ['POST'])]
    public function events(#[MapEntity(id: 'treeId')] FamilyTree $tree, string $mergeId, Request $request, TreeMergeSubscription $subscriptions): JsonResponse
    {
        return $subscriptions->create($request, $this->drafts->get($tree, $mergeId));
    }

    #[Route('/{mergeId}/process', name: 'api_tree_merge_process', methods: ['POST'])]
    public function process(#[MapEntity(id: 'treeId')] FamilyTree $tree, string $mergeId, Request $request): JsonResponse
    {
        $draft = $this->drafts->get($tree, $mergeId);
        $payload = $this->body->decodeObject($request);
        if (2 !== count($payload) || array_diff(['version', 'requestId'], array_keys($payload))) {
            throw new ApiException(422, 'TREE_MERGE_INVALID');
        }
        $this->conversation->process($draft, $payload['version'] ?? null, $payload['requestId'] ?? null);

        return new JsonResponse($this->views->create($draft));
    }

    #[Route('/{mergeId}/cancel', name: 'api_tree_merge_cancel', methods: ['POST'])]
    public function cancel(#[MapEntity(id: 'treeId')] FamilyTree $tree, string $mergeId, Request $request): JsonResponse
    {
        $draft = $this->drafts->get($tree, $mergeId);
        $this->drafts->cancel($draft, $this->body->decodeObject($request)['version'] ?? null);

        return new JsonResponse($this->views->create($draft));
    }

    #[Route('/{mergeId}/stop', name: 'api_tree_merge_stop', methods: ['POST'])]
    public function stop(#[MapEntity(id: 'treeId')] FamilyTree $tree, string $mergeId, Request $request): JsonResponse
    {
        $draft = $this->drafts->get($tree, $mergeId);
        $payload = $this->body->decodeObject($request);
        if (['requestId'] !== array_keys($payload)) {
            throw new ApiException(422, 'TREE_MERGE_INVALID');
        }
        $this->conversation->stop($draft, $payload['requestId']);

        return new JsonResponse($this->views->create($draft));
    }

    #[Route('/{mergeId}/proposal', name: 'api_tree_merge_proposal', methods: ['PUT'])]
    public function revise(#[MapEntity(id: 'treeId')] FamilyTree $tree, string $mergeId, Request $request): JsonResponse
    {
        $draft = $this->drafts->get($tree, $mergeId);
        $this->proposals->revise($draft, $this->body->decodeObject($request));

        return new JsonResponse($this->views->create($draft));
    }

    #[Route('/{mergeId}/revalidate', name: 'api_tree_merge_revalidate', methods: ['POST'])]
    public function revalidate(#[MapEntity(id: 'treeId')] FamilyTree $tree, string $mergeId, Request $request): JsonResponse
    {
        $draft = $this->drafts->get($tree, $mergeId);
        $this->proposals->revalidate($draft, $this->body->decodeObject($request)['version'] ?? null);

        return new JsonResponse($this->views->create($draft));
    }

    #[Route('/{mergeId}/apply', name: 'api_tree_merge_apply', methods: ['POST'])]
    public function apply(#[MapEntity(id: 'treeId')] FamilyTree $tree, string $mergeId, Request $request): JsonResponse
    {
        $draft = $this->drafts->get($tree, $mergeId);
        $this->proposals->apply($draft, $this->body->decodeObject($request), $request->headers->get('If-Match'));

        return new JsonResponse($this->views->create($draft));
    }
}
