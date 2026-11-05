<?php

namespace App\Application\TreeMerge;

use App\Api\ApiException;
use App\Application\Tree\ChangeSet\TreeChangeSetExecutor;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Model\TreeMergeProposal;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Tree preparation and mutation, usable independently of chat, AI and MCP. */
final readonly class TreeMergeService
{
    public function __construct(private TreeMergeProposalValidator $validator, private TreeChangeSetExecutor $executor,
        private TreeVersion $versions, private EntityManagerInterface $entityManager)
    {
    }

    /** @param list<array{id: string, role: string, content: string}> $sources */
    public function prepare(FamilyTree $tree, mixed $value, array $sources = [], ?TreeMergeProposal $current = null, bool $manual = false, int $version = 1): TreeMergeProposal
    {
        $validated = $this->validator->validate($tree, $value, $sources, $current?->proposal, $manual);
        $state = match (true) {
            [] !== $validated->questions => 'questions',
            !$validated->hasChanges      => 'no_changes',
            default                      => 'ready',
        };

        return new TreeMergeProposal($tree->getId()->toRfc4122(), $version, $this->versions->etag($tree), $validated->proposal, $state, $current?->id);
    }

    /** @return array<string, mixed> */
    public function apply(FamilyTree $tree, TreeMergeProposal $proposal, ?string $ifMatch): array
    {
        if ($proposal->treeId !== $tree->getId()->toRfc4122() || 'ready' !== $proposal->state) {
            throw new ApiException(409, 'TREE_MERGE_NOT_READY');
        }
        $result = $this->entityManager->wrapInTransaction(function () use ($tree, $proposal, $ifMatch): ?array {
            $this->entityManager->lock($tree, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($tree);
            if ($ifMatch !== $proposal->treeEtag || $this->versions->etag($tree) !== $proposal->treeEtag) {
                return null;
            }
            $validated = $this->validator->validate($tree, $this->validator->input($proposal->proposal), [], $proposal->proposal, true, false);
            if (null === $validated->changes) {
                throw new ApiException(409, 'TREE_MERGE_NOT_READY');
            }

            return $this->executor->execute($tree, $validated->changes, $proposal->treeEtag);
        });

        return $result ?? throw new ApiException(412, 'TREE_VERSION_CONFLICT');
    }

    public function revalidate(FamilyTree $tree, TreeMergeProposal $proposal): TreeMergeProposal
    {
        try {
            return $this->prepare($tree, $this->validator->input($proposal->proposal), [], $proposal, true, $proposal->version + 1);
        } catch (ApiException $error) {
            return new TreeMergeProposal($tree->getId()->toRfc4122(), $proposal->version + 1, $this->versions->etag($tree),
                array_replace($proposal->proposal, ['questions' => [$error->getErrorCode()]]), 'conflict', $proposal->id);
        }
    }
}
