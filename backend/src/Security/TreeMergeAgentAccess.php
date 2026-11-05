<?php

namespace App\Security;

use App\Api\ApiException;
use App\Entity\AuthSession;
use App\Repository\AuthSessionRepository;
use App\Repository\TreeMergeDraftRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/** User identity is scoped to one tool call, never to the shared MCP connection/session. */
final readonly class TreeMergeAgentAccess
{
    public function __construct(
        private TreeMergeAgentGrant $grants,
        private AuthSessionRepository $sessions,
        private TreeMergeDraftRepository $drafts,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private RequestStack $requests,
        private TokenStorageInterface $tokens,
    ) {
    }

    /** @template T
     * @param \Closure(): T $command
     *
     * @return T
     */
    public function run(string $accessToken, string $attemptId, \Closure $command): mixed
    {
        $request = $this->requests->getCurrentRequest() ?? throw new ApiException(401, 'TREE_MERGE_AGENT_REQUIRED');
        $previousToken = $this->tokens->getToken();
        $previousSession = $request->attributes->get(AuthSession::class);
        $previousGrant = $request->attributes->get(TreeMergeAgentGrant::class);
        try {
            $grant = $this->grants->resolve($accessToken);
            $session = $this->sessions->find($grant['sessionId']);
            if (null === $session) {
                throw new ApiException(401, 'TREE_MERGE_AGENT_REQUIRED');
            }
            $this->entityManager->refresh($session);
            if (!$session->isRefreshActive($this->clock->now()) || $session->getUser()->getId()->toRfc4122() !== $grant['userId']) {
                throw new ApiException(401, 'TREE_MERGE_AGENT_REQUIRED');
            }
            // Chat hydration must see the initiating login session, including on a reused ORM identity.
            $request->attributes->set(AuthSession::class, $session);
            $draft = $this->drafts->find($grant['draftId']);
            if (null === $draft) {
                throw new ApiException(401, 'TREE_MERGE_AGENT_REQUIRED');
            }
            $this->entityManager->refresh($draft);
            if ($draft->attemptId !== $attemptId || 'processing' !== $draft->state || !$draft->chatSessionAvailable
                || $draft->owner->getId()->toRfc4122() !== $grant['userId'] || $draft->tree->getId()->toRfc4122() !== $grant['treeId']) {
                throw new ApiException(409, 'TREE_MERGE_VERSION_CONFLICT');
            }
            $request->attributes->set(TreeMergeAgentGrant::class, $grant + ['attemptId' => $attemptId, 'version' => $draft->version]);
            $this->tokens->setToken(new UsernamePasswordToken($draft->owner, 'merge_agent', $draft->owner->getRoles()));

            return $command();
        } catch (AuthenticationException) {
            throw new ApiException(401, 'TREE_MERGE_AGENT_REQUIRED');
        } finally {
            $this->tokens->setToken($previousToken);
            foreach ([AuthSession::class => $previousSession, TreeMergeAgentGrant::class => $previousGrant] as $key => $value) {
                if (null === $value) {
                    $request->attributes->remove($key);
                } else {
                    $request->attributes->set($key, $value);
                }
            }
        }
    }
}
