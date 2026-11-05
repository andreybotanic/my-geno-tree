<?php

namespace App\Application\TreeMerge;

use App\Api\ApiException;
use App\Entity\AuthSession;
use App\Entity\TreeMergeDraft;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\AI\Agent\Bridge\Cache\MessageStore;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\NamespacedPoolInterface;

/** Application metadata and UI projection of messages kept by Symfony AI. */
final readonly class TreeMergeChat
{
    public function __construct(
        #[Target('tree_merge')] private MessageStoreInterface $store,
        private ClockInterface $clock,
        #[Target('cache.merge_session')] private CacheItemPoolInterface&NamespacedPoolInterface $cache,
    ) {
    }

    public static function sessionCache(CacheItemPoolInterface&NamespacedPoolInterface $cache, RequestStack $requests): CacheItemPoolInterface
    {
        $session = $requests->getMainRequest()?->attributes->get(AuthSession::class);
        if (!$session instanceof AuthSession) {
            throw new ApiException(401, 'AUTH_REQUIRED');
        }

        return $cache->withSubNamespace($session->getId()->toRfc4122());
    }

    public function load(TreeMergeDraft $draft): MessageBag
    {
        return $this->store->load($draft->conversationId);
    }

    public function initialize(TreeMergeDraft $draft): void
    {
        // An empty conversation must also have the official store's TTL.
        $messages = MessageBag::forId($draft->conversationId);
        $messages->getMetadata()->add('initialized', true);
        $this->store->save($messages);
    }

    public function exists(TreeMergeDraft $draft): bool
    {
        if (null === $draft->chatSessionId) {
            return false;
        }
        $store = new MessageStore($this->cache->withSubNamespace($draft->chatSessionId->toRfc4122()), 'tree_merge');

        $messages = $store->load($draft->conversationId);

        return count($messages) > 0 || $messages->getMetadata()->has('initialized');
    }

    public function append(TreeMergeDraft $draft, string $requestId, string $content, bool $failure = false): void
    {
        $message = $failure ? Message::forSystem('') : Message::ofUser($content);
        $message->getMetadata()->set(['requestId' => $requestId, 'createdAt' => $this->clock->now()->format(\DateTimeInterface::ATOM)]);
        if ($failure) {
            $message->getMetadata()->add('failureCode', $content);
        }
        $this->store->save($this->load($draft)->with($message));
    }

    public function clear(TreeMergeDraft $draft): void
    {
        if (null !== $draft->chatSessionId) {
            $store = new MessageStore($this->cache->withSubNamespace($draft->chatSessionId->toRfc4122()), 'tree_merge');
            $store->delete($draft->conversationId);
        }
        $this->store->delete($draft->conversationId);
    }

    /** @return list<array{id: string, requestId: string, role: string, content: string, createdAt: string, failureCode?: string}> */
    public function messages(TreeMergeDraft $draft): array
    {
        $rows = $outcomes = [];
        $requestId = '';
        foreach ($this->load($draft) as $message) {
            /** @var array{requestId?: string, createdAt?: string, failureCode?: string} $metadata */
            $metadata = $message->getMetadata()->all();
            if ($message instanceof UserMessage) {
                $requestId = $metadata['requestId'] ?? $message->getId()->toRfc4122();
                $content = $message->asText() ?? '';
            } elseif ($message instanceof AssistantMessage && null !== $message->asText()) {
                $content = $message->asText();
            } elseif ($message instanceof SystemMessage && $message->getMetadata()->has('failureCode')) {
                $content = '';
            } else {
                continue;
            }
            $row = ['id'    => $message->getId()->toRfc4122(),
                'requestId' => $metadata['requestId'] ?? $requestId,
                'role'      => $message->getRole()->value, 'content' => $content,
                'createdAt' => $metadata['createdAt'] ?? $message->getId()->getDateTime()->format(\DateTimeInterface::ATOM)];
            if (isset($metadata['failureCode'])) {
                $row['failureCode'] = $metadata['failureCode'];
            }
            if (!$message instanceof UserMessage) {
                $index = $outcomes[$row['requestId']] ?? count($rows);
                $row['id'] = $rows[$index]['id'] ?? $row['id'];
                $outcomes[$row['requestId']] = $index;
                $rows[$index] = $row;
            } else {
                $rows[] = $row;
            }
        }

        return array_values($rows);
    }
}
