<?php

namespace App\Controller\Api\Tree;

use App\Api\FamilyTreeViewFactory;
use App\Api\RequestBodyMapper;
use App\Application\Tree\CreateTreeService;
use App\Application\Tree\TreeVersion;
use App\Entity\User;
use App\Repository\FamilyTreeRepository;
use App\Request\Tree\TreeNameRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class TreeCollectionController
{
    public function __construct(
        private FamilyTreeRepository $trees,
        private FamilyTreeViewFactory $views,
        private RequestBodyMapper $requestBody,
        private CreateTreeService $createTree,
        private TreeVersion $versions,
    ) {
    }

    #[Route('/api/v1/trees', name: 'api_trees_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse([
            'items' => array_map(
                fn (array $result): array => $this->views->create(
                    $result[0],
                    (int)$result['personCount'],
                ),
                $this->trees->findAllWithPersonCountsForOwner($user),
            ),
        ]);
    }

    #[Route('/api/v1/trees', name: 'api_trees_create', methods: ['POST'])]
    public function create(
        Request $request,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $input = $this->requestBody->map($request, TreeNameRequest::class);
        $tree = $this->createTree->create($user, $input->name);
        $response = new JsonResponse(
            $this->views->create($tree, 0),
            JsonResponse::HTTP_CREATED,
        );
        $response->headers->set('ETag', $this->versions->etag($tree));
        $response->headers->set(
            'Location',
            sprintf(
                '/api/v1/trees/%s/snapshot',
                $tree->getId()->toRfc4122(),
            ),
        );

        return $response;
    }
}
