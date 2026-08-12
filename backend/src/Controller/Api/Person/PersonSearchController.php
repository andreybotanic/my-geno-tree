<?php

namespace App\Controller\Api\Person;

use App\Application\Person\PersonSearchService;
use App\Entity\FamilyTree;
use App\Security\FamilyTreeVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class PersonSearchController
{
    public function __construct(private PersonSearchService $search)
    {
    }

    #[Route(
        '/api/v1/trees/{treeId}/people/search',
        name: 'api_person_search',
        methods: ['GET'],
        priority: 10,
    )]
    #[IsGranted(
        FamilyTreeVoter::VIEW,
        subject: 'tree',
        statusCode: JsonResponse::HTTP_NOT_FOUND,
    )]
    public function __invoke(
        Request $request,
        #[MapEntity(id: 'treeId')] FamilyTree $tree,
    ): JsonResponse {
        return new JsonResponse([
            'items' => $this->search->search($tree, (string)$request->query->get('q', '')),
        ]);
    }
}
