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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\Serialize;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class TreeCollectionController extends AbstractController
{
    public function __construct(
        private readonly FamilyTreeRepository $trees,
        private readonly TreeViewReader $views,
        private readonly RequestBodyMapper $requestBody,
        private readonly CreateTreeService $createTree,
        private readonly TreeVersion $versions,
    ) {
    }

    /** @return array{items: list<TreeSummaryView>} */
    #[Route('/api/v1/trees', name: 'api_trees_list', methods: ['GET'])]
    #[Serialize]
    public function list(#[CurrentUser] User $user): array
    {
        return [
            'items' => array_map(
                fn (array $result): TreeSummaryView => $this->views->summary(
                    $result[0],
                    (int)$result['personCount'],
                ),
                $this->trees->findAllWithPersonCountsForOwner($user),
            ),
        ];
    }

    #[Route('/api/v1/trees', name: 'api_trees_create', methods: ['POST'])]
    public function create(
        Request $request,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $input = $this->requestBody->map($request, TreeNameRequest::class);
        $tree = $this->createTree->create($user, $input->name);

        return $this->json(
            $this->views->summary($tree, 0),
            JsonResponse::HTTP_CREATED,
            [
                'ETag'     => $this->versions->etag($tree),
                'Location' => sprintf('/api/v1/trees/%s/snapshot', $tree->getId()->toRfc4122()),
            ],
        );
    }
}
