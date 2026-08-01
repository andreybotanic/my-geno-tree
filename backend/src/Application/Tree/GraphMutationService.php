<?php

namespace App\Application\Tree;

use App\Api\ApiException;
use App\Application\Person\CreateFirstPersonService;
use App\Entity\FamilyTree;
use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class GraphMutationService
{
    public function __construct(
        private CreateFirstPersonService $createFirstPerson,
        private StructuralGraphMutationService $structuralMutations,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function mutate(
        FamilyTree $tree,
        array $payload,
        ?string $ifMatch,
    ): int {
        $type = $payload['type'] ?? null;
        if ('create_first_person' === $type) {
            $this->createFirstPerson->create($tree, $payload, $ifMatch);

            return JsonResponse::HTTP_CREATED;
        }
        if (!is_string($type)) {
            throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'GRAPH_MUTATION_INVALID', 'graph_mutation_invalid', ['type' => ['graph_mutation_type_invalid']]);
        }

        $this->structuralMutations->mutate($tree, $payload, $ifMatch);

        return str_starts_with($type, 'remove_')
            || 'delete_person' === $type
            || 'reflow_coordinates' === $type
            ? JsonResponse::HTTP_OK
            : JsonResponse::HTTP_CREATED;
    }
}
