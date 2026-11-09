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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class PersonEventController extends AbstractController
{
    public function __construct(
        private readonly PersonLookup $people,
        private readonly EventTimelineService $timeline,
        private readonly ObjectMapperInterface $mapper,
        private readonly TreeVersion $versions,
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

        return $this->json(
            [
                'items' => array_map(
                    fn (GenealogicalEvent $event): EventView => $this->mapper->map($event, EventView::class),
                    $this->timeline->forPerson($person),
                ),
            ],
            headers: ['ETag' => $this->versions->etag($tree)],
        );
    }
}
