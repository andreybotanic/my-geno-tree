<?php

namespace App\Controller\Api\Tree;

use App\Api\RequestBodyMapper;
use App\Api\TreeViewReader;
use App\Application\Tree\ChangeTreeReferenceService;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class TreeReferenceController extends AbstractController
{
    public function __construct(
        private readonly RequestBodyMapper $requestBody,
        private readonly ChangeTreeReferenceService $references,
        private readonly TreeViewReader $snapshots,
        private readonly TreeVersion $versions,
    ) {
    }

    #[Route(
        '/api/v1/trees/{treeId}/reference',
        name: 'api_tree_reference',
        methods: ['PUT'],
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
        $this->references->change(
            $tree,
            $this->requestBody->decodeObject($request),
            $request->headers->get('If-Match'),
        );

        return $this->json($this->snapshots->snapshot($tree), headers: ['ETag' => $this->versions->etag($tree)]);
    }
}
