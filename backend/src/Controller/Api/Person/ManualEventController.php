<?php

namespace App\Controller\Api\Person;

use App\Api\EventViewFactory;
use App\Api\RequestBodyMapper;
use App\Application\Event\ManualEventService;
use App\Application\Person\PersonLookup;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Entity\GenealogicalEvent;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(
    FamilyTreeVoter::EDIT,
    subject: 'tree',
    statusCode: JsonResponse::HTTP_NOT_FOUND,
)]
final readonly class ManualEventController
{
    public function __construct(
        private RequestBodyMapper $requestBody,
        private PersonLookup $people,
        private ManualEventService $events,
        private EventViewFactory $views,
        private TreeVersion $versions,
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
        $response = new JsonResponse([
            'items' => array_map(
                $this->views->create(...),
                $events,
            ),
            'etag' => $etag,
        ]);
        $response->headers->set('ETag', $etag);

        return $response;
    }

    private function eventResponse(
        FamilyTree $tree,
        GenealogicalEvent $event,
        int $status = JsonResponse::HTTP_OK,
    ): JsonResponse {
        $etag = $this->versions->etag($tree);
        $response = new JsonResponse([
            'event' => $this->views->create($event),
            'etag' => $etag,
        ], $status);
        $response->headers->set('ETag', $etag);

        return $response;
    }
}
