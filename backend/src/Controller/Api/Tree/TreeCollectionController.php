<?php

namespace App\Controller\Api\Tree;

use App\Api\RequestBodyMapper;
use App\Api\TreeViewReader;
use App\Api\View\TreeSummaryView;
use App\Application\Tree\CreateTreeService;
use App\Application\Tree\TreeVersion;
use App\Entity\User;
use App\Repository\FamilyTreeRepository;
use App\Request\Tree\TreeNameRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class TreeCollectionController
{
    public function __construct(
        private SerializerInterface $serializer,
        private FamilyTreeRepository $trees,
        private TreeViewReader $views,
        private RequestBodyMapper $requestBody,
        private CreateTreeService $createTree,
        private TreeVersion $versions,
    ) {
    }

    #[Route('/api/v1/trees', name: 'api_trees_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return JsonResponse::fromJsonString(
            $this->serializer->serialize(
                [
                    'items' => array_map(
                        fn (array $result): TreeSummaryView => $this->views->summary(
                            $result[0],
                            (int)$result['personCount'],
                        ),
                        $this->trees->findAllWithPersonCountsForOwner($user),
                    ),
                ],
                'json',
            ),
        );
    }

    #[Route('/api/v1/trees', name: 'api_trees_create', methods: ['POST'])]
    public function create(
        Request $request,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $input = $this->requestBody->map($request, TreeNameRequest::class);
        $tree = $this->createTree->create($user, $input->name);
        $response = JsonResponse::fromJsonString(
            $this->serializer->serialize($this->views->summary($tree, 0), 'json'),
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
