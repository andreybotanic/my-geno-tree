<?php

namespace App\Controller\Api\Person;

use App\Api\RequestBodyMapper;
use App\Api\View\PersonView;
use App\Api\ViewMapper;
use App\Application\Person\PersonLookup;
use App\Application\Person\UpdatePersonService;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class PersonController
{
    public function __construct(
        private PersonLookup $people,
        private ViewMapper $views,
        private RequestBodyMapper $requestBody,
        private UpdatePersonService $updatePerson,
        private TreeVersion $versions,
    ) {
    }

    #[Route(
        '/api/v1/trees/{treeId}/people/{personId}',
        name: 'api_person_get',
        methods: ['GET'],
    )]
    #[IsGranted(
        FamilyTreeVoter::VIEW,
        subject: 'tree',
        statusCode: JsonResponse::HTTP_NOT_FOUND,
    )]
    public function get(
        string $personId,
        #[MapEntity(id: 'treeId')] FamilyTree $tree,
    ): JsonResponse {
        $response = new JsonResponse(
            $this->views->one($this->people->require($tree, $personId), PersonView::class),
        );
        $response->headers->set('ETag', $this->versions->etag($tree));

        return $response;
    }

    #[Route(
        '/api/v1/trees/{treeId}/people/{personId}',
        name: 'api_person_patch',
        methods: ['PATCH'],
    )]
    #[IsGranted(
        FamilyTreeVoter::EDIT,
        subject: 'tree',
        statusCode: JsonResponse::HTTP_NOT_FOUND,
    )]
    public function patch(
        Request $request,
        string $personId,
        #[MapEntity(id: 'treeId')] FamilyTree $tree,
    ): JsonResponse {
        $person = $this->people->require($tree, $personId);
        $this->updatePerson->update(
            $tree,
            $person,
            $this->requestBody->decodeObject($request),
            $request->headers->get('If-Match'),
        );
        $etag = $this->versions->etag($tree);
        $response = new JsonResponse([
            'person' => $this->views->one($person, PersonView::class),
            'etag'   => $etag,
        ]);
        $response->headers->set('ETag', $etag);

        return $response;
    }
}
