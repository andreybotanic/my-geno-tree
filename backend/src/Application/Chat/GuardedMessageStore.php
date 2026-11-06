<?php

namespace App\Application\Chat;

use App\Api\ApiException;
use App\Entity\AuthSession;
use App\Model\Conversation;
use App\Security\ChatExecution;
use App\Security\ChatVoter;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\AbstractUid;
use Symfony\Component\Uid\TimeBasedUidInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/** Coordinates application metadata and native agent saves; the official bridge owns persistence. */
#[AsDecorator('ai.message_store.cache.chat')]
final class GuardedMessageStore implements MessageStoreInterface
{
    /** @var array<string, true> */
    private array $held = [];

    public function __construct(
        #[AutowireDecorated]
        private readonly MessageStoreInterface $inner,
        #[Target('cache.chat_index')]
        private readonly CacheItemPoolInterface $index,
        private readonly ClockInterface $clock,
        private readonly LockFactory $locks,
        private readonly RequestStack $requests,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {
    }

    public function load(AbstractUid&TimeBasedUidInterface $id): MessageBag
    {
        return $this->inner->load($id);
    }

    public function save(MessageBag $messages): void
    {
        $state = $messages->getMetadata()->get(Conversation::METADATA);
        if (!$state instanceof Conversation) {
            throw new \LogicException('Missing conversation metadata');
        }
        $this->synchronized(
            $state->contextKey,
            function () use ($messages): void {
                $execution = $this->requests->getMainRequest()?->attributes->get(ChatExecution::class);
                if ($execution instanceof ChatExecution) {
                    $this->assertRunning($execution);
                }
                $this->inner->save($messages);
            },
            $state->sessionId->toRfc4122()
        );
    }

    public function delete(AbstractUid&TimeBasedUidInterface $id): void
    {
        $state = $this->inner->load($id)->getMetadata()->get(Conversation::METADATA);
        if ($state instanceof Conversation) {
            $this->synchronized(
                $state->contextKey,
                function () use ($id, $state): void {
                    $this->inner->delete($id);
                    $this->index->deleteItem($this->indexKey($state->contextKey, $state->sessionId->toRfc4122()));
                },
                $state->sessionId->toRfc4122()
            );
        }
    }

    public function current(string $contextKey): ?Conversation
    {
        $key = $this->indexKey($contextKey);
        $id = $this->index->getItem($key)->get();
        $state = is_string($id) && Uuid::isValid($id) ? $this->state($id) : null;
        if (null === $state) {
            $this->index->deleteItem($key);
        }

        return $state;
    }

    public function get(string $id): Conversation
    {
        $state = Uuid::isValid($id) ? $this->state($id) : null;
        if (null === $state || $this->index->getItem($this->indexKey($state->contextKey))->get() !== $id) {
            throw new ApiException(404, 'CHAT_NOT_FOUND');
        }

        return $state;
    }

    /** @param array<string, string|null> $context */
    public function open(string $contextKey, string $profile, array $context): Conversation
    {
        return $this->synchronized(
            $contextKey,
            function () use ($contextKey, $profile, $context): Conversation {
                $current = $this->current($contextKey);
                if (null !== $current && $current->profile === $profile && $current->context === $context) {
                    return $current;
                }
                if (null !== $current) {
                    $this->delete($current->id);
                }
                $session = $this->requests->getMainRequest()?->attributes->get(AuthSession::class);
                if (!$session instanceof AuthSession) {
                    throw new ApiException(401, 'AUTH_REQUIRED');
                }
                $state = new Conversation(
                    $contextKey,
                    $profile,
                    $context,
                    $session->getId(),
                    $session->getUser()->getId(),
                    $this->clock->now(),
                );
                $messages = MessageBag::forId($state->id);
                $messages->getMetadata()->add(Conversation::METADATA, $state);
                $this->save($messages);
                $item = $this->index->getItem($this->indexKey($contextKey));
                $this->index->save($item->set($state->id->toRfc4122()));

                return $state;
            },
        );
    }

    public function persist(Conversation $state): void
    {
        $messages = $this->inner->load($state->id);
        if (!$messages->getMetadata()->has(Conversation::METADATA)) {
            throw new ApiException(404, 'CHAT_NOT_FOUND');
        }
        $messages->getMetadata()->add(Conversation::METADATA, $state);
        $this->save($messages);
        $execution = $this->requests->getMainRequest()?->attributes->get(ChatExecution::class);
        if ($execution instanceof ChatExecution && $execution->conversation === $state) {
            $execution->version = $state->version;
        }
    }

    public function execution(): ChatExecution
    {
        $execution = $this->requests->getMainRequest()?->attributes->get(ChatExecution::class);
        if (!$execution instanceof ChatExecution) {
            throw new ApiException(401, 'CHAT_AGENT_REQUIRED');
        }
        if (!$this->authorization->isGranted(ChatVoter::USE, $execution->conversation)) {
            throw new ApiException(403, 'CHAT_AGENT_REQUIRED');
        }
        $this->assertRunning($execution);

        return $execution;
    }

    /** Fast cancellation check; authorization is rechecked before model calls, tools and final output. */
    public function assertRunning(ChatExecution $execution): void
    {
        $stored = $this->inner->load($execution->conversation->id)->getMetadata()->get(Conversation::METADATA);
        if (!$stored instanceof Conversation || $stored->attemptId !== $execution->attemptId
            || $stored->version !== $execution->version || 'processing' !== $stored->status) {
            $execution->native?->cancel();
            throw new ApiException(409, 'CHAT_VERSION_CONFLICT');
        }
    }

    /** @template T
     * @param \Closure(): T $operation
     *
     * @return T
     */
    public function synchronized(string $contextKey, \Closure $operation, ?string $sessionId = null): mixed
    {
        $resource = 'chat_' . hash('sha256', $contextKey . ($sessionId ?? $this->sessionId()));
        if (isset($this->held[$resource])) {
            return $operation();
        }
        $lock = $this->locks->createLock($resource);
        $lock->acquire(true);
        $this->held[$resource] = true;
        try {
            return $operation();
        } finally {
            unset($this->held[$resource]);
            $lock->release();
        }
    }

    private function state(string $id): ?Conversation
    {
        $uuid = Uuid::fromString($id);
        $value = $uuid instanceof UuidV7 ? $this->inner->load($uuid)->getMetadata()->get(Conversation::METADATA) : null;

        return $value instanceof Conversation ? $value : null;
    }

    private function indexKey(string $contextKey, ?string $sessionId = null): string
    {
        return hash('sha256', $contextKey . ($sessionId ?? $this->sessionId()));
    }

    private function sessionId(): string
    {
        $session = $this->requests->getMainRequest()?->attributes->get(AuthSession::class);

        return $session instanceof AuthSession ? $session->getId()->toRfc4122() : '';
    }
}
