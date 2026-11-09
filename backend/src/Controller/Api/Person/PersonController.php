<?php

namespace App\Controller\Api\Person;

use App\Api\RequestBodyMapper;
use App\Api\View\PersonView;
use App\Application\Person\PersonLookup;
use App\Application\Person\UpdatePersonService;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class PersonController extends AbstractController
{
    public function __construct(
        private readonly PersonLookup $people,
        private readonly ObjectMapperInterface $mapper,
        private readonly RequestBodyMapper $requestBody,
        private readonly UpdatePersonService $updatePerson,
        private readonly TreeVersion $versions,
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
        return $this->json(
            $this->mapper->map($this->people->require($tree, $personId), PersonView::class),
            headers: ['ETag' => $this->versions->etag($tree)],
        );
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

        return $this->json(
            [
                'person' => $this->mapper->map($person, PersonView::class),
                'etag'   => $etag,
            ],
            headers: ['ETag' => $etag],
        );
    }
}
