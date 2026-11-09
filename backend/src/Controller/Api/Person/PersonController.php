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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class PersonController
{
    public function __construct(
        private SerializerInterface $serializer,
        private PersonLookup $people,
        private ObjectMapperInterface $mapper,
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
        $response = JsonResponse::fromJsonString(
            $this->serializer->serialize(
                $this->mapper->map($this->people->require($tree, $personId), PersonView::class),
                'json',
            ),
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
        $response = JsonResponse::fromJsonString(
            $this->serializer->serialize(
                [
                    'person' => $this->mapper->map($person, PersonView::class),
                    'etag'   => $etag,
                ],
                'json',
            ),
        );
        $response->headers->set('ETag', $etag);

        return $response;
    }
}
