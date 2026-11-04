<?php

namespace App\Security;

use App\Entity\TreeMergeDraft;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

final readonly class TreeMergeReadGrant
{
    public function __construct(
        #[Target('cache.merge_access')] private CacheItemPoolInterface $cache,
        private LockFactory $locks,
        private ClockInterface $clock,
    ) {
    }

    public function issue(TreeMergeDraft $draft, string $attemptId): string
    {
        $token = bin2hex(random_bytes(32));
        $item = $this->cache->getItem('merge_grant_' . hash('sha256', $token));
        $item->set(['userId' => $draft->owner->getId()->toRfc4122(), 'treeId' => $draft->tree->getId()->toRfc4122(),
            'draftId'        => $draft->id->toRfc4122(), 'attemptId' => $attemptId,
            // Streamable HTTP uses GET/POST and discovery in addition to the eight bounded tool reads.
            'expires' => $this->clock->now()->getTimestamp() + 300, 'remaining' => 32]);
        $item->expiresAfter(300);
        $this->cache->save($item);

        return $token;
    }

    /** @return array{userId: string, treeId: string, draftId: string, attemptId: string, expires: int, remaining: int} */
    public function consume(string $token): array
    {
        $key = 'merge_grant_' . hash('sha256', $token);
        $lock = $this->locks->createLock($key);
        $lock->acquire(true);
        try {
            $item = $this->cache->getItem($key);
            $grant = $item->get();
            /** @var array{userId: string, treeId: string, draftId: string, attemptId: string, expires: int, remaining: int}|null $grant */
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
