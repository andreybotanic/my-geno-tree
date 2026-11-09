<?php

namespace App\Controller\Api\Tree;

use App\Application\Tree\SearchRoutePlanService;
use App\Entity\FamilyTree;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class SearchRoutePlanController
{
    public function __construct(private SearchRoutePlanService $routes, private SerializerInterface $serializer)
    {
    }

    #[Route(
        '/api/v1/trees/{treeId}/route-to-reference/{personId}',
        name: 'api_tree_search_route_plan',
        methods: ['GET'],
    )]
    #[IsGranted(
        FamilyTreeVoter::VIEW,
        subject: 'tree',
        statusCode: JsonResponse::HTTP_NOT_FOUND,
    )]
    public function __invoke(
        string $personId,
        #[MapEntity(id: 'treeId')] FamilyTree $tree,
    ): JsonResponse {
        $plan = $this->routes->create($tree, $personId);
        $response = JsonResponse::fromJsonString($this->serializer->serialize($plan, 'json'));
        $etag = $plan['etag'];
        if (!is_string($etag)) {
            throw new \LogicException('Search route ETag must be a string.');
        }
        $response->headers->set('ETag', $etag);

        return $response;
    }
}
