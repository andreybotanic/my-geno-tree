<?php

namespace App\Controller\Api\Tree;

use App\Api\RequestBodyMapper;
use App\Api\View\MarriagePeriodView;
use App\Application\Tree\TreeVersion;
use App\Application\Tree\UpdateMarriagePeriodService;
use App\Entity\FamilyTree;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class MarriagePeriodController extends AbstractController
{
    public function __construct(
        private readonly RequestBodyMapper $requestBody,
        private readonly UpdateMarriagePeriodService $marriages,
        private readonly ObjectMapperInterface $mapper,
        private readonly TreeVersion $versions,
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

        return $this->json(
            [
                'marriage' => $this->mapper->map($marriage, MarriagePeriodView::class),
                'etag'     => $etag,
            ],
            headers: ['ETag' => $etag],
        );
    }
}
