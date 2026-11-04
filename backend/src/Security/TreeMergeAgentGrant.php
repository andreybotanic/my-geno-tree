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

final readonly class TreeMergeAgentGrant
{
    public function __construct(
        #[Target('cache.merge_access')] private CacheItemPoolInterface $cache,
        private LockFactory $locks,
        private ClockInterface $clock,
        private RequestStack $requests,
    ) {
    }

    public function issue(TreeMergeDraft $draft, string $attemptId, ?AuthSession $session = null): string
    {
        $session ??= $this->requests->getMainRequest()?->attributes->get(AuthSession::class);
        if (!$session instanceof AuthSession || !$session->isRefreshActive($this->clock->now())
            || !$session->getUser()->getId()->equals($draft->owner->getId())) {
            throw new CustomUserMessageAuthenticationException('invalid_merge_session');
        }
        $token = bin2hex(random_bytes(32));
        $item = $this->cache->getItem('merge_grant_' . hash('sha256', $token));
        $item->set(['userId' => $draft->owner->getId()->toRfc4122(), 'treeId' => $draft->tree->getId()->toRfc4122(),
            'draftId'        => $draft->id->toRfc4122(), 'attemptId' => $attemptId, 'version' => $draft->version,
            'sessionId'      => $session->getId()->toRfc4122(),
            // Streamable HTTP discovery and notifications count as requests, too.
            'expires' => $this->clock->now()->getTimestamp() + 300, 'remaining' => 64]);
        $item->expiresAfter(300);
        $this->cache->save($item);

        return $token;
    }

    /** @return array{userId: string, treeId: string, draftId: string, attemptId: string, version: int, sessionId: string, expires: int, remaining: int} */
    public function consume(string $token): array
    {
        $key = 'merge_grant_' . hash('sha256', $token);
        $lock = $this->locks->createLock($key);
        $lock->acquire(true);
        try {
            $item = $this->cache->getItem($key);
            $grant = $item->get();
            /** @var array{userId: string, treeId: string, draftId: string, attemptId: string, version: int, sessionId: string, expires: int, remaining: int}|null $grant */
            if (!$item->isHit() || !is_array($grant) || $grant['expires'] <= $this->clock->now()->getTimestamp() || $grant['remaining'] <= 0) {
                throw new CustomUserMessageAuthenticationException('invalid_merge_access');
            }
            --$grant['remaining'];
            $item->set($grant);
            $this->cache->save($item);

            return $grant;
        } finally {
            $lock->release();
        }
    }

    public function revoke(string $token): void
    {
        $this->cache->deleteItem('merge_grant_' . hash('sha256', $token));
    }
}
