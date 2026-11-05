<?php

namespace App\Application\Chat\Ai;

use App\Api\ApiException;
use App\Application\Chat\Chat;
use App\Application\Chat\ChatLocks;
use App\Model\Conversation;
use App\Security\ChatExecution;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\AbstractUid;
use Symfony\Component\Uid\TimeBasedUidInterface;

/** Adds atomic lifecycle checks to the official store, including saves made by the native agent. */
#[AsDecorator('ai.message_store.cache.chat')]
final readonly class LockedMessageStore implements MessageStoreInterface
{
    public function __construct(#[AutowireDecorated] private MessageStoreInterface $inner, private ChatLocks $locks, private RequestStack $requests)
    {
    }

    public function load(AbstractUid&TimeBasedUidInterface $id): MessageBag
    {
        return $this->inner->load($id);
    }

    public function save(MessageBag $messages): void
    {
        $conversation = $messages->getMetadata()->get(Chat::METADATA);
        if (!$conversation instanceof Conversation) {
            throw new \LogicException('Missing conversation metadata');
        }
        $this->locks->synchronized($conversation->contextKey, $conversation->sessionId->toRfc4122(), function () use ($messages): void {
            $execution = $this->requests->getMainRequest()?->attributes->get(ChatExecution::class);
            if ($execution instanceof ChatExecution) {
                $stored = $this->inner->load($messages->getId())->getMetadata()->get(Chat::METADATA);
                if (!$stored instanceof Conversation || $stored->attemptId !== $execution->attemptId || $stored->version !== $execution->version
                    || 'processing' !== $stored->status) {
                    throw new ApiException(409, 'CHAT_VERSION_CONFLICT');
                }
            }
            $this->inner->save($messages);
        });
    }

    public function delete(AbstractUid&TimeBasedUidInterface $id): void
    {
        // All application deletions already hold the context lock.
        $this->inner->delete($id);
    }
}
