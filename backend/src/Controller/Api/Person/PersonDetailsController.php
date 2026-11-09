<?php

namespace App\Controller\Api\Person;

use App\Api\TreeViewReader;
use App\Application\Person\PersonLookup;
use App\Entity\FamilyTree;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class PersonDetailsController
{
    public function __construct(
        private SerializerInterface $serializer,
        private PersonLookup $people,
        private TreeViewReader $details,
    ) {
    }

    #[Route(
        '/api/v1/trees/{treeId}/people/{personId}/details',
        name: 'api_person_details',
        methods: ['GET'],
    )]
    #[IsGranted(
        FamilyTreeVoter::VIEW,
        subject: 'tree',
        statusCode: JsonResponse::HTTP_NOT_FOUND,
    )]
    public function __invoke(
        string $personId,
        #[MapEntity(id: 'treeId')] FamilyTree $tree,
    ): JsonResponse {
        $details = $this->details->personDetails($tree, $this->people->require($tree, $personId));
        $response = JsonResponse::fromJsonString($this->serializer->serialize($details, 'json'));
        $response->headers->set('ETag', $details->etag);

        return $response;
    }
}
