<?php

namespace App\Controller\Api\Person;

use App\Api\View\EventView;
use App\Application\Event\EventTimelineService;
use App\Application\Person\PersonLookup;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Entity\GenealogicalEvent;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class PersonEventController
{
    public function __construct(
        private SerializerInterface $serializer,
        private PersonLookup $people,
        private EventTimelineService $timeline,
        private ObjectMapperInterface $mapper,
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
        $response = JsonResponse::fromJsonString(
            $this->serializer->serialize(
                [
                    'items' => array_map(
                        fn (GenealogicalEvent $event): EventView => $this->mapper->map($event, EventView::class),
                        $this->timeline->forPerson($person),
                    ),
                ],
                'json',
            ),
        );
        $response->headers->set('ETag', $this->versions->etag($tree));

        return $response;
    }
}
