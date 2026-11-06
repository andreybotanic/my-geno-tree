<?php

namespace App\Tool;

use App\Api\ApiException;
use App\Application\Chat\ChatInterface;
use App\Application\Tree\TreeContextService;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(
    name: 'get_tree_context',
    description: <<<'DESCRIPTION'
    Read the selected tree, narrative reference, bounded immediate family and ETag.
    DESCRIPTION,
    method: 'tree'
)]
#[AsTool(
    name: 'find_people',
    description: <<<'DESCRIPTION'
    Search names; read candidate records before deciding
    whether a name uniquely identifies someone.
    DESCRIPTION,
    method: 'find'
)]
#[AsTool(
    name: 'get_person_context',
    description: <<<'DESCRIPTION'
    Read a person and bounded immediate family in the selected tree.
    DESCRIPTION,
    method: 'person'
)]
final readonly class TreeReadTools
{
    public function __construct(private TreeContextService $context, private ChatInterface $chat)
    {
    }

    /** @return array<string, mixed> */
    public function tree(string $treeId, ?string $referencePersonId = null): array
    {
        $this->scope($treeId);

        return $this->observed($this->context->context($treeId, $referencePersonId));
    }

    /** @return array<string, mixed> */
    public function find(string $treeId, string $query): array
    {
        $this->scope($treeId);

        return $this->observed(['query' => $query, 'candidates' => $this->context->find($treeId, $query)]);
    }

    /** @return array<string, mixed> */
    public function person(string $treeId, string $personId): array
    {
        $this->scope($treeId);

        return $this->observed($this->context->context($treeId, $personId));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function observed(array $data): array
    {
        $execution = $this->chat->execution();
        $execution->observations[] = $data;
        if (count($execution->observations) > 8 || strlen(json_encode($execution->observations, JSON_THROW_ON_ERROR)) > 60000) {
            throw new ApiException(429, 'TREE_CONTEXT_LIMIT');
        }

        return $data;
    }

    private function scope(string $treeId): void
    {
        if (($this->chat->execution()->conversation->context['treeId'] ?? null) !== $treeId) {
            throw new ApiException(404, 'TREE_NOT_FOUND');
        }
    }
}
