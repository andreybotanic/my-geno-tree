<?php

namespace App\Security;

use App\Entity\AuthSession;
use App\Entity\TreeMergeDraft;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Uid\Uuid;

final readonly class TreeMergeAgentGrant
{
    public function __construct(
        #[Target('cache.merge_agent')] private CacheItemPoolInterface $cache,
        private LockFactory $locks,
        private ClockInterface $clock,
        private RequestStack $requests,
    ) {
    }

    public function issue(TreeMergeDraft $draft, ?AuthSession $session = null): string
    {
        $session ??= $this->requests->getMainRequest()?->attributes->get(AuthSession::class);
        if (!$session instanceof AuthSession || !$session->isRefreshActive($this->clock->now())
            || !$session->getUser()->getId()->equals($draft->owner->getId())) {
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
                    $this->resolve($token);
                } catch (CustomUserMessageAuthenticationException) {
                    $token = null;
                }
            }
            if (!is_string($token)) {
                $token = Uuid::v4()->toRfc4122();
            }
            $item = $this->cache->getItem('merge_grant_' . hash('sha256', $token));
            $item->set(['userId' => $draft->owner->getId()->toRfc4122(), 'treeId' => $draft->tree->getId()->toRfc4122(),
                'draftId'        => $draft->id->toRfc4122(), 'sessionId' => $session->getId()->toRfc4122(),
                'expires'        => $this->clock->now()->getTimestamp() + 3600]);
            $this->cache->save($item->expiresAfter(3600));
            $this->cache->save($slot->set($token)->expiresAfter(3600));

            return $token;
        } finally {
            $lock->release();
        }
    }

    /** @return array{userId: string, treeId: string, draftId: string, sessionId: string, expires: int} */
    public function resolve(string $token): array
    {
        $item = $this->cache->getItem('merge_grant_' . hash('sha256', $token));
        /** @var array{userId: string, treeId: string, draftId: string, sessionId: string, expires: int}|null $grant */
        $grant = $item->get();
        if (!$item->isHit() || !is_array($grant) || $grant['expires'] <= $this->clock->now()->getTimestamp()) {
            throw new CustomUserMessageAuthenticationException('invalid_merge_access');
        }

        return $grant;
    }

    public function revoke(string $token): void
    {
        $this->cache->deleteItem('merge_grant_' . hash('sha256', $token));
    }
}
