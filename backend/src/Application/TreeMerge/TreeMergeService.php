<?php

namespace App\Application\TreeMerge;

use App\Api\ApiException;
use App\Application\Tree\ChangeSet\TreeChangeSetExecutor;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Model\TreeMergeProposal;

/** Tree preparation and mutation, usable independently of chat, AI and MCP. */
final readonly class TreeMergeService
{
    public function __construct(
        private TreeMergeProposalValidator $validator,
        private TreeChangeSetExecutor $executor,
        private TreeVersion $versions,
        private TreeChangeProposalValidator $changes,
    ) {
    }

    /** @param list<array{id: string, role: string, content: string}> $sources */
    public function prepare(
        FamilyTree $tree,
        mixed $value,
        array $sources = [],
        ?TreeMergeProposal $current = null,
        bool $manual = false,
        int $version = 1,
    ): TreeMergeProposal {
        $validated = is_array($value) && array_key_exists('operations', $value)
            ? $this->changes->validate($tree, $value, $sources, $current?->proposal, $manual)
            : $this->validator->validate($tree, $value, $sources, $current?->proposal, $manual);
        $state = match (true) {
            [] !== $validated->questions => 'questions',
            !$validated->hasChanges      => 'no_changes',
            default                      => 'ready',
        };

        return new TreeMergeProposal(
            $tree->getId()->toRfc4122(),
            $version,
            $this->versions->etag($tree),
            $validated->proposal,
            $state,
            $current?->id,
        );
    }

    /** @return array<string, mixed> */
    public function apply(FamilyTree $tree, TreeMergeProposal $proposal, ?string $ifMatch): array
    {
        if ($proposal->treeId !== $tree->getId()->toRfc4122() || 'ready' !== $proposal->state) {
            throw new ApiException(409, 'TREE_MERGE_NOT_READY');
        }
        if ($ifMatch !== $proposal->treeEtag) {
            throw new ApiException(412, 'TREE_VERSION_CONFLICT');
        }
        $this->versions->assertMatches($tree, $ifMatch);
        $input = $this->validator->input($proposal->proposal);
        $validated = isset($input['operations'])
            ? $this->changes->validate($tree, $input, [], $proposal->proposal, true)
            : $this->validator->validate(
                $tree,
                $input,
                [],
                $proposal->proposal,
                true,
                false,
            );
        if (null === $validated->changes) {
            throw new ApiException(409, 'TREE_MERGE_NOT_READY');
        }

        return $this->executor->execute($tree, $validated->changes, $proposal->treeEtag);
    }

    public function revalidate(FamilyTree $tree, TreeMergeProposal $proposal): TreeMergeProposal
    {
        try {
            return $this->prepare(
                $tree,
                $this->validator->input($proposal->proposal),
                [],
                $proposal,
                true,
                $proposal->version + 1,
            );
        } catch (ApiException $error) {
            return new TreeMergeProposal(
                $tree->getId()->toRfc4122(),
                $proposal->version + 1,
                $this->versions->etag($tree),
                array_replace($proposal->proposal, ['questions' => [$error->getErrorCode()]]),
                'conflict',
                $proposal->id
            );
        }
    }
}
