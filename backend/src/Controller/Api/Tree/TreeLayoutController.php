<?php

namespace App\Controller\Api\Tree;

use App\Api\RequestBodyMapper;
use App\Application\Tree\Layout\SaveTreeLayoutService;
use App\Entity\FamilyTree;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class TreeLayoutController
{
    public function __construct(
        private RequestBodyMapper $requestBody,
        private SaveTreeLayoutService $layouts,
    ) {
    }

    #[Route(
        '/api/v1/trees/{treeId}/layout',
        name: 'api_tree_layout',
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
    ): Response {
        $this->layouts->save($tree, $this->requestBody->decodeObject($request));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
