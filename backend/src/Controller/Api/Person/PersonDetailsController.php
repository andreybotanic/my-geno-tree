<?php

namespace App\Controller\Api\Person;

use App\Api\PersonDetailsViewFactory;
use App\Application\Person\PersonLookup;
use App\Entity\FamilyTree;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class PersonDetailsController
{
    public function __construct(
        private PersonLookup $people,
        private PersonDetailsViewFactory $details,
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
        $details = $this->details->create($tree, $this->people->require($tree, $personId));
        $response = new JsonResponse($details);
        $etag = $details['etag'];
        if (!is_string($etag)) {
            throw new \LogicException('Person details ETag must be a string.');
        }
        $response->headers->set('ETag', $etag);

        return $response;
    }
}
