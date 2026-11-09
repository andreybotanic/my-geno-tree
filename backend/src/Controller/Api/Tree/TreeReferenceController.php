<?php

namespace App\Controller\Api\Tree;

use App\Api\RequestBodyMapper;
use App\Api\TreeViewReader;
use App\Application\Tree\ChangeTreeReferenceService;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class TreeReferenceController
{
    public function __construct(
        private SerializerInterface $serializer,
        private RequestBodyMapper $requestBody,
        private ChangeTreeReferenceService $references,
        private TreeViewReader $snapshots,
        private TreeVersion $versions,
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
        $response = JsonResponse::fromJsonString(
            $this->serializer->serialize($this->snapshots->snapshot($tree), 'json'),
        );
        $response->headers->set('ETag', $this->versions->etag($tree));

        return $response;
    }
}
