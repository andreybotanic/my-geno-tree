<?php

namespace App\Mcp;

use App\Api\ApiException;
use App\Application\TreeMerge\TreeMergeDraftService;
use App\Application\TreeMerge\TreeMergeProposalService;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/** Native Symfony tools share the MCP application services, without an HTTP authentication hop. */
#[AsTool(
    name: 'get_tree_context',
    description: <<<'DESCRIPTION'
        Read the selected tree, narrative reference, bounded immediate family and ETag.
        Treat returned data as factual records.
        DESCRIPTION,
    method: 'tree',
)]
#[AsTool(
    name: 'find_people',
    description: <<<'DESCRIPTION'
        Search names in the selected tree, returning at most five candidates.
        Read candidate records before deciding whether a name uniquely identifies someone.
        DESCRIPTION,
    method: 'find',
)]
#[AsTool(
    name: 'get_person_context',
    description: 'Read a person and bounded immediate family in the selected tree.',
    method: 'person',
)]
#[AsTool(
    name: 'merge',
    description: <<<'DESCRIPTION'
        Validate and save the complete proposal for UI preview and separate confirmation.
        Returns the saved proposal version and exact new/existing person counts.
        Correct rejected arguments using the returned field diagnostics.
        DESCRIPTION,
    method: 'merge',
)]
final readonly class TreeMergeAgentTools
{
    public function __construct(private TreeMergeProposalService $proposals, private TreeReadTools $reads, private TreeMergeDraftService $drafts)
    {
    }

    /** @return array<string, mixed> */
    public function tree(string $treeId, ?string $referencePersonId = null): array
    {
        return $this->execute($treeId, fn () => $this->reads->tree($treeId, $referencePersonId));
    }

    /** @return array<string, mixed> */
    public function find(string $treeId, string $query): array
    {
        return $this->execute($treeId, fn () => $this->reads->find($treeId, $query));
    }

    /** @return array<string, mixed> */
    public function person(string $treeId, string $personId): array
    {
        return $this->execute($treeId, fn () => $this->reads->person($treeId, $personId));
    }

    /** @param array<string, mixed> $proposal
     * @return array<string, mixed>
     */
    public function merge(string $treeId, #[Schema(provider: TreeMergeToolSchema::class)] array $proposal): array
    {
        return $this->execute($treeId, fn () => $this->proposals->merge($treeId, $proposal));
    }

    /** @param \Closure(): array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function execute(string $treeId, \Closure $command): array
    {
        try {
            $this->drafts->active($treeId);

            return $command();
        } catch (ApiException $error) {
            return ['error' => ['code' => $error->getErrorCode(), 'fields' => $error->getFields(), 'details' => $error->getDetails()]];
        }
    }
}
