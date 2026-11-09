<?php

namespace App\Controller\Api\Tree;

use App\Api\RequestBodyMapper;
use App\Api\View\MarriagePeriodView;
use App\Api\ViewMapper;
use App\Application\Tree\TreeVersion;
use App\Application\Tree\UpdateMarriagePeriodService;
use App\Entity\FamilyTree;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class MarriagePeriodController
{
    public function __construct(
        private RequestBodyMapper $requestBody,
        private UpdateMarriagePeriodService $marriages,
        private ViewMapper $views,
        private TreeVersion $versions,
    ) {
    }

    #[Route(
        '/api/v1/trees/{treeId}/marriages/{marriageId}',
        name: 'api_tree_marriage_update',
        methods: ['PATCH'],
    )]
    #[IsGranted(
        FamilyTreeVoter::EDIT,
        subject: 'tree',
        statusCode: JsonResponse::HTTP_NOT_FOUND,
    )]
    public function update(
        Request $request,
        #[MapEntity(id: 'treeId')] FamilyTree $tree,
        string $marriageId,
    ): JsonResponse {
        $marriage = $this->marriages->update(
            $tree,
            $marriageId,
            $this->requestBody->decodeObject($request),
            $request->headers->get('If-Match'),
        );
        $etag = $this->versions->etag($tree);
        $response = new JsonResponse([
            'marriage' => $this->views->one($marriage, MarriagePeriodView::class),
            'etag'     => $etag,
        ]);
        $response->headers->set('ETag', $etag);

        return $response;
    }
}
