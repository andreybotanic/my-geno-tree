<?php

namespace App\Controller\Api\Tree;

use App\Api\TreeViewReader;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class TreeSnapshotController
{
    public function __construct(
        private TreeViewReader $snapshots,
        private TreeVersion $versions,
    ) {
    }

    #[Route(
        '/api/v1/trees/{treeId}/snapshot',
        name: 'api_tree_snapshot',
        methods: ['GET'],
    )]
    #[IsGranted(
        FamilyTreeVoter::VIEW,
        subject: 'tree',
        statusCode: JsonResponse::HTTP_NOT_FOUND,
    )]
    public function __invoke(
        #[MapEntity(id: 'treeId')] FamilyTree $tree,
    ): JsonResponse {
        $response = new JsonResponse($this->snapshots->snapshot($tree));
        $response->headers->set('ETag', $this->versions->etag($tree));

        return $response;
    }
}
