<?php

namespace App\Controller\Api\Tree;

use App\Api\TreeViewReader;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class TreeSnapshotController extends AbstractController
{
    public function __construct(
        private readonly TreeViewReader $snapshots,
        private readonly TreeVersion $versions,
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
        return $this->json($this->snapshots->snapshot($tree), headers: ['ETag' => $this->versions->etag($tree)]);
    }
}
