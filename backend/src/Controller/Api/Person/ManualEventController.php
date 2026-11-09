<?php

namespace App\Controller\Api\Person;

use App\Api\RequestBodyMapper;
use App\Api\View\EventView;
use App\Application\Event\ManualEventService;
use App\Application\Person\PersonLookup;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Entity\GenealogicalEvent;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(
    FamilyTreeVoter::EDIT,
    subject: 'tree',
    statusCode: JsonResponse::HTTP_NOT_FOUND,
)]
final class ManualEventController extends AbstractController
{
    public function __construct(
        private readonly RequestBodyMapper $requestBody,
        private readonly PersonLookup $people,
        private readonly ManualEventService $events,
        private readonly ObjectMapperInterface $mapper,
        private readonly TreeVersion $versions,
    ) {
    }

    #[Route(
        '/api/v1/trees/{treeId}/people/{personId}/events',
        name: 'api_person_event_create',
        methods: ['POST'],
    )]
    public function create(
        Request $request,
        #[MapEntity(id: 'treeId')] FamilyTree $tree,
        string $personId,
    ): JsonResponse {
        $person = $this->people->require($tree, $personId);
        $event = $this->events->create(
            $tree,
            $person,
            $this->requestBody->decodeObject($request),
            $request->headers->get('If-Match'),
        );

        return $this->eventResponse(
            $tree,
            $event,
            JsonResponse::HTTP_CREATED,
        );
    }

    #[Route(
        '/api/v1/trees/{treeId}/people/{personId}/events/{eventId}',
        name: 'api_person_event_update',
        methods: ['PATCH'],
    )]
    public function update(
        Request $request,
        #[MapEntity(id: 'treeId')] FamilyTree $tree,
        string $personId,
        string $eventId,
    ): JsonResponse {
        $person = $this->people->require($tree, $personId);
        $event = $this->events->update(
            $tree,
            $person,
            $eventId,
            $this->requestBody->decodeObject($request),
            $request->headers->get('If-Match'),
        );

        return $this->eventResponse($tree, $event);
    }

    #[Route(
        '/api/v1/trees/{treeId}/people/{personId}/events/{eventId}',
        name: 'api_person_event_delete',
        methods: ['DELETE'],
    )]
    public function delete(
        Request $request,
        #[MapEntity(id: 'treeId')] FamilyTree $tree,
        string $personId,
        string $eventId,
    ): JsonResponse {
        $person = $this->people->require($tree, $personId);
        $this->events->delete(
            $tree,
            $person,
            $eventId,
            $request->headers->get('If-Match'),
        );
        $response = new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
        $response->headers->set('ETag', $this->versions->etag($tree));

        return $response;
    }

    #[Route(
        '/api/v1/trees/{treeId}/people/{personId}/event-order',
        name: 'api_person_event_order',
        methods: ['PUT'],
    )]
    public function reorder(
        Request $request,
        #[MapEntity(id: 'treeId')] FamilyTree $tree,
        string $personId,
    ): JsonResponse {
        $person = $this->people->require($tree, $personId);
        $events = $this->events->reorder(
            $tree,
            $person,
            $this->requestBody->decodeObject($request),
            $request->headers->get('If-Match'),
        );
        $etag = $this->versions->etag($tree);

        return $this->json(
            [
                'items' => array_map(
                    fn (GenealogicalEvent $item): EventView => $this->mapper->map($item, EventView::class),
                    $events,
                ),
                'etag' => $etag,
            ],
            headers: ['ETag' => $etag],
        );
    }

    private function eventResponse(
        FamilyTree $tree,
        GenealogicalEvent $event,
        int $status = JsonResponse::HTTP_OK,
    ): JsonResponse {
        $etag = $this->versions->etag($tree);

        return $this->json(
            [
                'event' => $this->mapper->map($event, EventView::class),
                'etag'  => $etag,
            ],
            $status,
            headers: ['ETag' => $etag],
        );
    }
}
