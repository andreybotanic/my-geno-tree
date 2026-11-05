<?php

namespace App\Mcp;

use App\Api\ApiException;
use App\Application\TreeMerge\TreeMergeAgentCommands;
use App\Application\TreeMerge\TreeMergeAgentScope;
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
    name: 'prepare_tree_merge_proposal',
    description: <<<'DESCRIPTION'
        Validate and stage the complete explicit proposal for UI review.
        Returns proposalId and exact new/existing person counts.
        Correct rejected arguments using the returned field diagnostics.
        DESCRIPTION,
    method: 'prepare',
)]
#[AsTool(
    name: 'reply_to_tree_merge',
    description: <<<'DESCRIPTION'
        Publish the user-visible reply verbatim and end this attempt.
        Use one sentence of at most 350 characters; a proposal reply states its new-person count
        and the reason for any omissions. Pass proposalId to submit a staged proposal for UI review.
        Use needsClarification=true to ask an essential question.
        DESCRIPTION,
    method: 'reply',
)]
final readonly class TreeMergeAgentTools
{
    public function __construct(private TreeMergeAgentCommands $commands, private TreeReadTools $reads, private TreeMergeAgentScope $scope)
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
    public function prepare(string $treeId, #[Schema(provider: TreeMergeToolSchema::class)] array $proposal): array
    {
        return $this->execute($treeId, fn () => $this->commands->prepare($treeId, $proposal));
    }

    /** @return array<string, mixed> */
    public function reply(string $treeId, #[Schema(minLength: 1, maxLength: 350)] string $reply, ?string $proposalId = null, bool $needsClarification = false): array
    {
        return $this->execute($treeId, fn () => $this->commands->reply($treeId, $reply, $proposalId, $needsClarification));
    }

    /** @param \Closure(): array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function execute(string $treeId, \Closure $command): array
    {
        try {
            $this->scope->active($treeId);

            return $command();
        } catch (ApiException $error) {
            return ['error' => ['code' => $error->getErrorCode(), 'fields' => $error->getFields(), 'details' => $error->getDetails()]];
        }
    }
}
