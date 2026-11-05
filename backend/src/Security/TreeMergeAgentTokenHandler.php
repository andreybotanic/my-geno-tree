<?php

namespace App\Security;

use App\Entity\AuthSession;
use App\Entity\TreeMergeDraft;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Uid\Uuid;

/** Issues chat-only credentials and authenticates them against the initiating login session. */
#[AsAlias(AccessTokenHandlerInterface::class, target: 'treeMergeAgent')]
final readonly class TreeMergeAgentTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        #[Target('cache.merge_agent')] private CacheItemPoolInterface $cache,
        private LockFactory $locks,
        private ClockInterface $clock,
        private RequestStack $requests,
        private EntityManagerInterface $entityManager,
        private AccessDecisionManagerInterface $decisions,
    ) {
    }

    public function issue(TreeMergeDraft $draft, ?AuthSession $session = null): string
    {
        $session ??= $this->requests->getMainRequest()?->attributes->get(AuthSession::class);
        if (!$session instanceof AuthSession || !$session->isRefreshActive($this->clock->now())
            || !$this->decisions->decide(new PostAuthenticationToken($session->getUser(), 'merge_agent', $session->getUser()->getRoles()), [FamilyTreeVoter::EDIT], $draft->tree)) {
            throw new CustomUserMessageAuthenticationException('invalid_merge_session');
        }
        $scope = 'merge_chat_' . $session->getId() . '_' . $draft->id;
        $lock = $this->locks->createLock($scope);
        $lock->acquire(true);
        try {
            $slot = $this->cache->getItem($scope);
            $token = $slot->get();
            if (is_string($token)) {
                try {
                    $this->credential($token);
                } catch (CustomUserMessageAuthenticationException) {
                    $token = null;
                }
            }
            if (!is_string($token)) {
                $token = Uuid::v4()->toRfc4122();
            }
            $item = $this->cache->getItem('merge_token_' . hash('sha256', $token));
            $item->set(['draftId' => $draft->id->toRfc4122(), 'sessionId' => $session->getId()->toRfc4122(),
                'expires'         => $this->clock->now()->getTimestamp() + 3600]);
            $this->cache->save($item->expiresAfter(3600));
            $this->cache->save($slot->set($token)->expiresAfter(3600));

            return $token;
        } finally {
            $lock->release();
        }
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $credential = $this->credential($accessToken);
        $session = $this->entityManager->find(AuthSession::class, $credential['sessionId']);
        if (null === $session) {
            throw new CustomUserMessageAuthenticationException('invalid_merge_session');
        }
        // Recheck revocation even when several tool calls share the ORM identity map.
        $this->entityManager->refresh($session);
        if (!$session->isRefreshActive($this->clock->now())) {
            throw new CustomUserMessageAuthenticationException('invalid_merge_session');
        }

        return new UserBadge($session->getUser()->getUserIdentifier(), fn () => $session->getUser(),
            ['session'                                                       => $session, 'draftId' => $credential['draftId']]);
    }

    public function revoke(#[\SensitiveParameter] string $token): void
    {
        $this->cache->deleteItem('merge_token_' . hash('sha256', $token));
    }

    /** @return array{draftId: string, sessionId: string, expires: int} */
    private function credential(#[\SensitiveParameter] string $token): array
    {
        $item = $this->cache->getItem('merge_token_' . hash('sha256', $token));
        /** @var array{draftId: string, sessionId: string, expires: int}|null $credential */
        $credential = $item->get();
        if (!$item->isHit() || !is_array($credential) || $credential['expires'] <= $this->clock->now()->getTimestamp()) {
            throw new CustomUserMessageAuthenticationException('invalid_merge_access');
        }

        return $credential;
    }
}
