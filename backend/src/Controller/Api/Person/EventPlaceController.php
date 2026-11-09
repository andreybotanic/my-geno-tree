<?php

namespace App\Controller\Api\Person;

use App\Api\RequestBodyMapper;
use App\Api\View\EventView;
use App\Api\ViewMapper;
use App\Application\Event\EventPlaceService;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(FamilyTreeVoter::EDIT, subject: 'tree', statusCode: JsonResponse::HTTP_NOT_FOUND)]
final readonly class EventPlaceController
{
    public function __construct(
        private RequestBodyMapper $requestBody,
        private EventPlaceService $places,
        private ViewMapper $views,
        private TreeVersion $versions,
    ) {
    }

    #[Route('/api/v1/trees/{treeId}/events/{eventId}/place', name: 'api_event_place_put', methods: ['PUT'])]
    public function put(
        Request $request,
        #[MapEntity(id: 'treeId')] FamilyTree $tree,
        string $eventId,
    ): JsonResponse {
        $event = $this->places->put(
            $tree,
            $eventId,
            $this->requestBody->decodeObject($request),
            $request->headers->get('If-Match'),
        );
        $etag = $this->versions->etag($tree);
        $response = new JsonResponse(['event' => $this->views->one($event, EventView::class), 'etag' => $etag]);
        $response->headers->set('ETag', $etag);

        return $response;
    }

    #[Route('/api/v1/trees/{treeId}/events/{eventId}/place', name: 'api_event_place_delete', methods: ['DELETE'])]
    public function delete(
        Request $request,
        #[MapEntity(id: 'treeId')] FamilyTree $tree,
        string $eventId,
    ): JsonResponse {
        $this->places->delete($tree, $eventId, $request->headers->get('If-Match'));
        $response = new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
        $response->headers->set('ETag', $this->versions->etag($tree));

        return $response;
    }
}
