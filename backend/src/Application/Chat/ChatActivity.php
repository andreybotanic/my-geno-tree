<?php

namespace App\Application\Chat;

use App\Api\ApiException;
use App\Model\Conversation;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Lock\LockFactory;

/** @phpstan-type Usage array{input: int, output: int, total: int}
 * @phpstan-type Activity array{progress: list<string>, contexts: list<array<string, mixed>>, usage: Usage}
 */
final readonly class ChatActivity
{
    public function __construct(#[Target('cache.chat')] private CacheItemPoolInterface $cache, private LockFactory $locks, private ChatEvents $events)
    {
    }

    /** @return Activity */
    public function read(Conversation $conversation): array
    {
        return $this->get($conversation->id->toRfc4122(), $conversation->attemptId ?? 'none');
    }

    public function step(Conversation $conversation, string $step): void
    {
        $this->update($conversation->id->toRfc4122(), $conversation->attemptId ?? 'none', $step);
    }

    public function stop(Conversation $conversation, string $requestId): void
    {
        $item = $this->cache->getItem('stop_' . $conversation->id->toRfc4122() . '_' . hash('sha256', $requestId));
        $this->cache->save($item->set(true)->expiresAfter(3600));
    }

    public function assertRunning(Conversation $conversation): void
    {
        if ($this->isStopped($conversation)) {
            throw new ApiException(409, 'CHAT_STOPPED');
        }
    }

    public function isStopped(Conversation $conversation): bool
    {
        $requestId = $conversation->attemptRequestId ?? '';

        return $this->cache->getItem('stop_' . $conversation->id->toRfc4122() . '_' . hash('sha256', $requestId))->isHit();
    }

    /** @param array<string, mixed> $context */
    public function observed(string $conversationId, string $attemptId, string $step, array $context): void
    {
        $this->update($conversationId, $attemptId, $step, $context);
    }

    public function tokens(Conversation $conversation, int $input, int $output, int $total): void
    {
        $this->update($conversation->id->toRfc4122(), $conversation->attemptId ?? 'none', null, null, ['input' => $input, 'output' => $output, 'total' => $total]);
    }

    /** @return Activity */
    private function get(string $conversationId, string $attemptId): array
    {
        /** @var Activity|null $value */
        $value = $this->cache->getItem('activity_' . $conversationId . '_' . $attemptId)->get();

        return $value ?? ['progress' => [], 'contexts' => [], 'usage' => ['input' => 0, 'output' => 0, 'total' => 0]];
    }

    /** @param array<string, mixed>|null $context
     * @param Usage|null $usage
     */
    private function update(string $conversationId, string $attemptId, ?string $step, ?array $context = null, ?array $usage = null): void
    {
        $key = 'activity_' . $conversationId . '_' . $attemptId;
        $lock = $this->locks->createLock($key);
        $lock->acquire(true);
        try {
            $value = $this->get($conversationId, $attemptId);
            if (null !== $step && !in_array($step, $value['progress'], true)) {
                $value['progress'][] = $step;
            }
            if (null !== $context) {
                $value['contexts'][] = $context;
                if (strlen(json_encode($value['contexts'], JSON_THROW_ON_ERROR)) > 60000) {
                    throw new ApiException(429, 'CHAT_CONTEXT_LIMIT');
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
        $this->events->progress($conversationId, $attemptId, $value['progress'], $value['usage']);
    }
}
