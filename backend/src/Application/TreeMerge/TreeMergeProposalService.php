<?php

namespace App\Application\TreeMerge;

use App\Api\ApiException;
use App\Application\Tree\ChangeSet\TreeChangeSetExecutor;
use App\Application\Tree\TreeVersion;
use App\Entity\TreeMergeDraft;
use App\Security\TreeMergeAgentContext;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class TreeMergeProposalService
{
    public function __construct(
        private TreeMergeProposalValidator $validator,
        private TreeChangeSetExecutor $executor,
        private TreeVersion $versions,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private TreeMergeDraftService $drafts,
        private MergeActivity $activity,
        private RequestStack $requests,
    ) {
    }

    /** @param array<string, mixed> $proposal
     * @return array{newPeople: int, existingPeople: int, state: string, proposalVersion: int}
     */
    public function merge(string $treeId, array $proposal): array
    {
        $draft = $this->drafts->active($treeId);
        $this->entityManager->refresh($draft->tree);
        $etag = $this->versions->etag($draft->tree);
        $this->activity->step($draft, 'validating');
        $observed = [];
        foreach ($this->activity->read($draft)['contexts'] as $context) {
            foreach (is_array($context['people'] ?? null) ? $context['people'] : [] as $person) {
                if (is_array($person) && is_string($person['id'] ?? null)) {
                    $observed[] = $person['id'];
                }
            }
        }
        foreach (is_array($proposal['people'] ?? null) ? $proposal['people'] : [] as $person) {
            if (is_array($person) && null !== ($person['existingPersonId'] ?? null)
                && !in_array($person['existingPersonId'], $observed, true)) {
                throw new ApiException(422, 'TREE_MERGE_UNOBSERVED_PERSON', details: ['instruction' => 'Read the existing person with get_person_context before referencing it.']);
            }
        }
        $validated = $this->validator->validate($draft, $proposal);
        // Expected conflicts roll back without closing the entity manager used by the agent's next tool.
        $this->entityManager->beginTransaction();
        try {
            $this->lock($draft);
            $this->drafts->active($treeId);
            if ($etag !== $this->versions->etag($draft->tree)) {
                throw new ApiException(409, 'TREE_MERGE_TREE_CONFLICT');
            }
            $requestId = array_find(array_reverse($draft->messages), static fn ($message) => 'user' === $message['role'])['requestId']
                ?? throw new ApiException(409, 'TREE_MERGE_VERSION_CONFLICT');
            $draft->markSource($requestId);
            $this->save($draft, $validated, false, true);
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $error) {
            $this->entityManager->rollback();
            $this->entityManager->refresh($draft);
            throw $error;
        }
        $context = $this->requests->getCurrentRequest()?->attributes->get(TreeMergeAgentContext::class);
        if ($context instanceof TreeMergeAgentContext) {
            $context->version = $draft->version;
        }
        /** @var list<array{resolution: string}> $people */
        $people = $validated->proposal['people'];

        return [
            'newPeople'      => count(array_filter($people, static fn ($person) => 'new' === $person['resolution'])),
            'existingPeople' => count(array_filter($people, static fn ($person) => 'existing' === $person['resolution'])),
            'state'          => match (true) {
                [] !== $validated->questions => 'questions',
                $validated->hasChanges       => 'ready',
                default                      => 'no_changes',
            },
            'proposalVersion' => $draft->proposalVersion,
        ];
    }

    /** @param array<string, mixed> $payload */
    public function revise(TreeMergeDraft $draft, array $payload): void
    {
        if (2 !== count($payload) || !array_key_exists('version', $payload) || !array_key_exists('proposal', $payload)) {
            throw new ApiException(422, 'TREE_MERGE_INVALID', 'tree_merge_invalid');
        }
        $this->entityManager->wrapInTransaction(function () use ($draft, $payload): void {
            $this->lock($draft);
            $this->editable($draft, $payload['version']);
            $validated = $this->validator->validate($draft, $payload['proposal'], true);
            $this->save($draft, $validated, true);
        });
    }

    public function revalidate(TreeMergeDraft $draft, mixed $version): void
    {
        $this->entityManager->wrapInTransaction(function () use ($draft, $version): void {
            $this->lock($draft);
            $this->editable($draft, $version);
            $this->validateSaved($draft);
        });
    }

    /** @param array<string, mixed> $payload */
    public function apply(TreeMergeDraft $draft, array $payload, ?string $ifMatch): void
    {
        if (2 !== count($payload) || !is_int($payload['version'] ?? null) || !is_int($payload['proposalVersion'] ?? null)) {
            throw new ApiException(422, 'TREE_MERGE_INVALID', 'tree_merge_invalid');
        }
        $conflict = $this->entityManager->wrapInTransaction(function () use ($draft, $payload, $ifMatch): bool {
            $this->lock($draft);
            if ('applied' === $draft->state) {
                return false;
            }
            $this->editable($draft, $payload['version']);
            if ($draft->proposalVersion !== $payload['proposalVersion']
                || $draft->preparedSourceVersion !== $draft->sourceVersion || 'ready' !== $draft->state
                || null === $draft->proposal) {
                throw new ApiException(409, 'TREE_MERGE_NOT_READY', 'tree_merge_not_ready');
            }
            $etag = $this->versions->etag($draft->tree);
            if ($etag !== $draft->treeEtag || $ifMatch !== $draft->treeEtag) {
                $this->validateSaved($draft);

                return true;
            }
            $validated = $this->validator->validate($draft, $this->validator->input($draft->proposal), true);
            if (null === $validated->changes) {
                throw new ApiException(409, 'TREE_MERGE_NOT_READY', 'tree_merge_not_ready');
            }
            $result = $this->executor->execute($draft->tree, $validated->changes, $etag);
            $draft->applied($result + ['proposalVersion' => $draft->proposalVersion], $this->clock->now());

            return false;
        });
        if ($conflict) {
            // Throw only after committing the revalidation, preserving all source text and corrections.
            throw new ApiException(412, 'TREE_VERSION_CONFLICT', 'tree_version_conflict');
        }
    }

    private function validateSaved(TreeMergeDraft $draft): void
    {
        if (null === $draft->proposal || $draft->preparedSourceVersion !== $draft->sourceVersion
            || in_array($draft->state, ['collecting', 'processing', 'error'], true)) {
            throw new ApiException(409, 'TREE_MERGE_NOT_READY', 'tree_merge_not_ready');
        }
        $proposal = $draft->proposal;
        try {
            $validated = $this->validator->validate($draft, $this->validator->input($proposal), true);
            $this->save($draft, $validated, false);
        } catch (ApiException $error) {
            $draft->propose($proposal, [$error->getErrorCode()],
                $this->versions->etag($draft->tree), 'conflict', false, $this->clock->now());
        }
    }

    private function save(TreeMergeDraft $draft, ValidatedTreeMergeProposal $validated, bool $correction, bool $keepProcessing = false): void
    {
        $state = match (true) {
            [] !== $validated->questions => 'questions',
            !$validated->hasChanges      => 'no_changes',
            default                      => 'ready',
        };
        $draft->propose($validated->proposal, $validated->questions, $this->versions->etag($draft->tree),
            $state, $correction, $this->clock->now(), $keepProcessing);
    }

    private function lock(TreeMergeDraft $draft): void
    {
        // Always tree before draft, including retries and concurrent structural changes.
        $this->entityManager->lock($draft->tree, LockMode::PESSIMISTIC_WRITE);
        $this->entityManager->refresh($draft->tree);
        $this->entityManager->lock($draft, LockMode::PESSIMISTIC_WRITE);
        $this->entityManager->refresh($draft);
    }

    private function editable(TreeMergeDraft $draft, mixed $version): void
    {
        if ($draft->version !== $version) {
            throw new ApiException(409, 'TREE_MERGE_VERSION_CONFLICT', 'tree_merge_version_conflict');
        }
        if (in_array($draft->state, ['cancelled', 'applied'], true)) {
            throw new ApiException(409, 'TREE_MERGE_CLOSED', 'tree_merge_closed');
        }
    }
}
