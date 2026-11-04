<?php

namespace App\Application\TreeMerge;

use App\Api\ApiException;
use App\Entity\TreeMergeDraft;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Lock\LockFactory;

/** @phpstan-type Usage array{input: int, output: int, total: int}
 * @phpstan-type Activity array{progress: list<string>, contexts: list<array<string, mixed>>, usage: Usage}
 */
final readonly class MergeActivity
{
    public function __construct(#[Target('cache.merge_session')] private CacheItemPoolInterface $cache, private LockFactory $locks, private TreeMergeEvents $events)
    {
    }

    /** @return Activity */
    public function read(TreeMergeDraft $draft): array
    {
        return $this->get($draft->id->toRfc4122(), $draft->attemptId ?? 'none');
    }

    public function step(TreeMergeDraft $draft, string $step): void
    {
        $this->update($draft->id->toRfc4122(), $draft->attemptId ?? 'none', $step);
    }

    public function stop(TreeMergeDraft $draft, string $requestId): void
    {
        $item = $this->cache->getItem('stop_' . $draft->id->toRfc4122() . '_' . hash('sha256', $requestId));
        $this->cache->save($item->set(true)->expiresAfter(3600));
    }

    public function assertRunning(TreeMergeDraft $draft): void
    {
        if ($this->isStopped($draft)) {
            throw new ApiException(409, 'TREE_MERGE_STOPPED');
        }
    }

    public function isStopped(TreeMergeDraft $draft): bool
    {
        $requestId = array_find(array_reverse($draft->messages), static fn ($message) => 'user' === $message['role'])['requestId'] ?? '';

        return $this->cache->getItem('stop_' . $draft->id->toRfc4122() . '_' . hash('sha256', $requestId))->isHit();
    }

    /** @param array<string, mixed> $context */
    public function observed(string $draftId, string $attemptId, string $step, array $context): void
    {
        $this->update($draftId, $attemptId, $step, $context);
    }

    public function tokens(TreeMergeDraft $draft, int $input, int $output, int $total): void
    {
        $this->update($draft->id->toRfc4122(), $draft->attemptId ?? 'none', null, null, ['input' => $input, 'output' => $output, 'total' => $total]);
    }

    /** @return Activity */
    private function get(string $draftId, string $attemptId): array
    {
        /** @var Activity|null $value */
        $value = $this->cache->getItem('activity_' . $draftId . '_' . $attemptId)->get();

        return $value ?? ['progress' => [], 'contexts' => [], 'usage' => ['input' => 0, 'output' => 0, 'total' => 0]];
    }

    /** @param array<string, mixed>|null $context
     * @param Usage|null $usage
     */
    private function update(string $draftId, string $attemptId, ?string $step, ?array $context = null, ?array $usage = null): void
    {
        $key = 'activity_' . $draftId . '_' . $attemptId;
        $lock = $this->locks->createLock($key);
        $lock->acquire(true);
        try {
            $value = $this->get($draftId, $attemptId);
            if (null !== $step && !in_array($step, $value['progress'], true)) {
                $value['progress'][] = $step;
            }
            if (null !== $context) {
                $value['contexts'][] = $context;
                if (count($value['contexts']) > 8 || strlen(json_encode($value['contexts'], JSON_THROW_ON_ERROR)) > 60000) {
                    throw new ApiException(429, 'TREE_CONTEXT_LIMIT');
                }
            }
            if (null !== $usage) {
                foreach ($usage as $name => $tokens) {
                    $value['usage'][$name] += $tokens;
                }
            }
            $item = $this->cache->getItem($key);
            $item->set($value)->expiresAfter(3600);
            $this->cache->save($item);
        } finally {
            $lock->release();
        }
        $this->events->progress($draftId, $attemptId, $value['progress'], $value['usage']);
    }
}
