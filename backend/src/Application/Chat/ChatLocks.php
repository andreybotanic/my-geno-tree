<?php

namespace App\Application\Chat;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/** Reuses the acquired Symfony lock when a MessageStore save is nested in a chat operation. */
final class ChatLocks
{
    /** @var array<string, LockInterface> */
    private array $held = [];

    public function __construct(private readonly LockFactory $factory)
    {
    }

    /** @template T
     * @param \Closure(): T $operation
     *
     * @return T
     */
    public function synchronized(string $contextKey, string $sessionId, \Closure $operation): mixed
    {
        $resource = 'chat_' . hash('sha256', $contextKey . $sessionId);
        if (isset($this->held[$resource])) {
            return $operation();
        }
        $lock = $this->factory->createLock($resource);
        $lock->acquire(true);
        $this->held[$resource] = $lock;
        try {
            return $operation();
        } finally {
            unset($this->held[$resource]);
            $lock->release();
        }
    }
}
