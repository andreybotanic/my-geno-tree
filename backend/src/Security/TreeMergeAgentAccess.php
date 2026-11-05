<?php

namespace App\Security;

use App\Api\ApiException;
use App\Entity\AuthSession;
use App\Repository\TreeMergeDraftRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/** User identity is scoped to one tool call, never to the shared MCP connection/session. */
final readonly class TreeMergeAgentAccess
{
    public function __construct(
        #[Target('treeMergeAgent')] private AccessTokenHandlerInterface $tokenHandler,
        private TreeMergeDraftRepository $drafts,
        private EntityManagerInterface $entityManager,
        private RequestStack $requests,
        private TokenStorageInterface $tokens,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    /** @template T
     * @param \Closure(): T $command
     *
     * @return T
     */
    public function run(#[\SensitiveParameter] string $accessToken, string $attemptId, \Closure $command): mixed
    {
        $request = $this->requests->getCurrentRequest() ?? throw new ApiException(401, 'TREE_MERGE_AGENT_REQUIRED');
        $previousToken = $this->tokens->getToken();
        $previousSession = $request->attributes->get(AuthSession::class);
        $previousContext = $request->attributes->get(TreeMergeAgentContext::class);
        try {
            $badge = $this->tokenHandler->getUserBadgeFrom($accessToken);
            $attributes = $badge->getAttributes();
            $session = $attributes['session'] ?? null;
            $draftId = $attributes['draftId'] ?? null;
            if (!$session instanceof AuthSession || !is_string($draftId)) {
                throw new ApiException(401, 'TREE_MERGE_AGENT_REQUIRED');
            }
            // Chat hydration must see the initiating login session, including on a reused ORM identity.
            $request->attributes->set(AuthSession::class, $session);
            $user = $badge->getUser();
            $this->tokens->setToken(new PostAuthenticationToken($user, 'merge_agent', $user->getRoles()));
            $draft = $this->drafts->find($draftId);
            if (null === $draft || !$this->authorization->isGranted(FamilyTreeVoter::EDIT, $draft->tree)) {
                throw new ApiException(403, 'TREE_MERGE_AGENT_REQUIRED');
            }
            $this->entityManager->refresh($draft);
            if ($draft->attemptId !== $attemptId || 'processing' !== $draft->state || !$draft->chatSessionAvailable) {
                throw new ApiException(409, 'TREE_MERGE_VERSION_CONFLICT');
            }
            $request->attributes->set(TreeMergeAgentContext::class, new TreeMergeAgentContext($draft, $attemptId));

            return $command();
        } catch (AuthenticationException) {
            throw new ApiException(401, 'TREE_MERGE_AGENT_REQUIRED');
        } finally {
            $this->tokens->setToken($previousToken);
            foreach ([AuthSession::class => $previousSession, TreeMergeAgentContext::class => $previousContext] as $key => $value) {
                if (null === $value) {
                    $request->attributes->remove($key);
                } else {
                    $request->attributes->set($key, $value);
                }
            }
        }
    }
}
