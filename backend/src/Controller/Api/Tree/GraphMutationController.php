<?php

namespace App\Controller\Api\Tree;

use App\Api\RequestBodyMapper;
use App\Api\TreeSnapshotViewFactory;
use App\Application\Tree\GraphMutationService;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class GraphMutationController
{
    public function __construct(
        private RequestBodyMapper $requestBody,
        private GraphMutationService $mutations,
        private TreeSnapshotViewFactory $snapshots,
        private TreeVersion $versions,
    ) {
    }

    #[Route(
        '/api/v1/trees/{treeId}/graph-mutations',
        name: 'api_tree_graph_mutation',
        methods: ['POST'],
    )]
    #[IsGranted(
        FamilyTreeVoter::EDIT,
        subject: 'tree',
        statusCode: JsonResponse::HTTP_NOT_FOUND,
    )]
    public function __invoke(
        Request $request,
        #[MapEntity(id: 'treeId')] FamilyTree $tree,
    ): JsonResponse {
        $status = $this->mutations->mutate(
            $tree,
            $this->requestBody->decodeObject($request),
            $request->headers->get('If-Match'),
        );
        $response = new JsonResponse(
            $this->snapshots->create($tree),
            $status,
        );
        $response->headers->set('ETag', $this->versions->etag($tree));

        return $response;
    }
}
