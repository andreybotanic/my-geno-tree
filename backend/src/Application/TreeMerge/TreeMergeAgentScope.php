<?php

namespace App\Application\TreeMerge;

use App\Api\ApiException;
use App\Entity\TreeMergeDraft;
use App\Repository\TreeMergeDraftRepository;
use App\Security\TreeMergeAgentContext;
use App\Security\TreeMergeAgentVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/** Binds tool execution to the selected draft and its current processing attempt. */
final readonly class TreeMergeAgentScope
{
    public function __construct(
        private TreeMergeDraftRepository $drafts,
        private EntityManagerInterface $entityManager,
        private RequestStack $requests,
        private AuthorizationCheckerInterface $authorization,
        private MergeActivity $activity,
    ) {
    }

    /** @template T
     * @param \Closure(): T $command
     *
     * @return T
     */
    public function run(TreeMergeDraft $draft, string $attemptId, \Closure $command): mixed
    {
        $request = $this->requests->getCurrentRequest() ?? throw new ApiException(401, 'TREE_MERGE_AGENT_REQUIRED');
        $previous = $request->attributes->get(TreeMergeAgentContext::class);
        $request->attributes->set(TreeMergeAgentContext::class, new TreeMergeAgentContext($draft, $attemptId));
        try {
            $this->active($draft->tree->getId()->toRfc4122());

            return $command();
        } finally {
            if (null === $previous) {
                $request->attributes->remove(TreeMergeAgentContext::class);
            } else {
                $request->attributes->set(TreeMergeAgentContext::class, $previous);
            }
        }
    }

    public function active(string $treeId): TreeMergeDraft
    {
        $context = $this->requests->getCurrentRequest()?->attributes->get(TreeMergeAgentContext::class);
        if (!$context instanceof TreeMergeAgentContext) {
            throw new ApiException(401, 'TREE_MERGE_AGENT_REQUIRED');
        }
        if ($context->treeId !== $treeId) {
            throw new ApiException(404, 'TREE_NOT_FOUND');
        }
        $draft = $this->drafts->find($context->draftId) ?? throw new ApiException(404, 'TREE_MERGE_NOT_FOUND');
        if (!$this->authorization->isGranted(TreeMergeAgentVoter::USE, $draft)) {
            throw new ApiException(403, 'TREE_MERGE_AGENT_REQUIRED');
        }
        $this->entityManager->refresh($draft);
        if ($draft->attemptId !== $context->attemptId || $draft->version !== $context->version
            || 'processing' !== $draft->state || !$draft->chatSessionAvailable) {
            throw new ApiException(409, 'TREE_MERGE_VERSION_CONFLICT');
        }
        $this->activity->assertRunning($draft);

        return $draft;
    }
}
