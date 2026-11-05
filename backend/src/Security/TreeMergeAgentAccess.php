<?php

namespace App\Security;

use App\Api\ApiException;
use App\Application\TreeMerge\MergeActivity;
use App\Entity\AuthSession;
use App\Entity\TreeMergeDraft;
use App\Repository\TreeMergeDraftRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/** In-process attempt scope; identity stays in Symfony Security and never reaches the model. */
final readonly class TreeMergeAgentAccess
{
    public function __construct(
        private TreeMergeDraftRepository $drafts,
        private EntityManagerInterface $entityManager,
        private RequestStack $requests,
        private AuthorizationCheckerInterface $authorization,
        private ClockInterface $clock,
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
        $request = $this->requests->getCurrentRequest();
        $context = $request?->attributes->get(TreeMergeAgentContext::class);
        $session = $request?->attributes->get(AuthSession::class);
        if (!$context instanceof TreeMergeAgentContext || !$session instanceof AuthSession) {
            throw new ApiException(401, 'TREE_MERGE_AGENT_REQUIRED');
        }
        // Recheck logout even when the initiating request still holds a security token.
        $this->entityManager->refresh($session);
        if (!$session->isRefreshActive($this->clock->now())) {
            throw new ApiException(401, 'TREE_MERGE_AGENT_REQUIRED');
        }
        if ($context->treeId !== $treeId) {
            throw new ApiException(404, 'TREE_NOT_FOUND');
        }
        $draft = $this->drafts->find($context->draftId);
        if (null === $draft || !$this->authorization->isGranted(FamilyTreeVoter::EDIT, $draft->tree)) {
            throw new ApiException(404, 'TREE_NOT_FOUND');
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
