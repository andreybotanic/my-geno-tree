<?php

namespace App\Controller\Api\Tree;

use App\Api\RequestBodyMapper;
use App\Api\TreeViewReader;
use App\Application\Tree\SaveBloodNetworkStateService;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Request\Tree\SaveBloodNetworkStateRequest;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class BloodNetworkStateController extends AbstractController
{
    public function __construct(
        private readonly RequestBodyMapper $requestBody,
        private readonly SaveBloodNetworkStateService $states,
        private readonly TreeViewReader $snapshots,
        private readonly TreeVersion $versions,
    ) {
    }

    #[Route(
        '/api/v1/trees/{treeId}/blood-network-state',
        name: 'api_tree_blood_network_state_put',
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
        $input = $this->requestBody->map($request, SaveBloodNetworkStateRequest::class);
        $this->states->save($tree, $input->state(), $request->headers->get('If-Match'));

        return $this->json($this->snapshots->snapshot($tree), headers: ['ETag' => $this->versions->etag($tree)]);
    }
}
