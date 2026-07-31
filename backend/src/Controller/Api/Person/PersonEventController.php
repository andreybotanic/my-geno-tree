<?php

namespace App\Controller\Api\Person;

use App\Api\EventViewFactory;
use App\Application\Event\EventTimelineService;
use App\Application\Person\PersonLookup;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class PersonEventController
{
    public function __construct(
        private PersonLookup $people,
        private EventTimelineService $timeline,
        private EventViewFactory $views,
        private TreeVersion $versions,
    ) {
    }

    #[Route(
        '/api/v1/trees/{treeId}/people/{personId}/events',
        name: 'api_person_events',
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
        $person = $this->people->require($tree, $personId);
        $response = new JsonResponse([
            'items' => array_map(
                $this->views->create(...),
                $this->timeline->forPerson($person),
            ),
        ]);
        $response->headers->set('ETag', $this->versions->etag($tree));

        return $response;
    }
}
