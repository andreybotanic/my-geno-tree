<?php

namespace App\Application\Chat;

use App\Api\ApiException;
use App\Entity\AuthSession;
use App\Model\Conversation;
use App\Security\ChatExecution;
use App\Security\ChatVoter;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/** Resolves conversations and application metadata; Symfony AI stores all messages. */
final readonly class Chat
{
    public const string METADATA = 'conversation';
    public const int MAX_INPUT_LENGTH = 10000;

    public function __construct(
        #[Target('chat')] private MessageStoreInterface $store,
        #[Target('cache.chat_index')] private CacheItemPoolInterface $index,
        private ClockInterface $clock,
        private ChatLocks $locks,
        private RequestStack $requests,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    public function current(string $contextKey): ?Conversation
    {
        $key = hash('sha256', $contextKey . $this->indexNamespace());
        $id = $this->index->getItem($key)->get();
        if (!is_string($id) || !Uuid::isValid($id)) {
            return null;
        }
        $conversation = $this->load($id);
        if (null === $conversation) {
            $this->index->deleteItem($key);
        }

        return $conversation;
    }

    public function get(string $id): Conversation
    {
        $conversation = Uuid::isValid($id) ? $this->load($id) : null;
        if (null === $conversation || $this->current($conversation->contextKey)?->id->toRfc4122() !== $id) {
            throw new ApiException(404, 'CHAT_NOT_FOUND');
        }

        return $conversation;
    }

    /** @param array<string, string|null> $context */
    public function create(string $contextKey, string $profile, array $context): Conversation
    {
        return $this->synchronized($contextKey, function () use ($contextKey, $profile, $context): Conversation {
            $current = $this->current($contextKey);
            if (null !== $current && $current->profile === $profile && $current->context === $context) {
                return $current;
            }
            if (null !== $current) {
                $this->store->delete($current->id);
            }
            $session = $this->requests->getMainRequest()?->attributes->get(AuthSession::class);
            if (!$session instanceof AuthSession) {
                throw new ApiException(401, 'AUTH_REQUIRED');
            }
            $conversation = new Conversation($contextKey, $profile, $context, $session->getId(), $session->getUser()->getId(), $this->clock->now());
            $messages = MessageBag::forId($conversation->id);
            $messages->getMetadata()->add(self::METADATA, $conversation);
            $this->store->save($messages);
            $this->index->save($this->index->getItem(hash('sha256', $contextKey . $this->indexNamespace()))->set($conversation->id->toRfc4122()));

            return $conversation;
        });
    }

    /** @template T
     * @param \Closure(): T $operation
     *
     * @return T
     */
    public function synchronized(string $contextKey, \Closure $operation): mixed
    {
        return $this->locks->synchronized($contextKey, $this->indexNamespace(), $operation);
    }

    public function active(): Conversation
    {
        $execution = $this->requests->getCurrentRequest()?->attributes->get(ChatExecution::class);
        if (!$execution instanceof ChatExecution) {
            throw new ApiException(401, 'CHAT_AGENT_REQUIRED');
        }
        $current = $this->get($execution->conversation->id->toRfc4122());
        if (!$this->authorization->isGranted(ChatVoter::USE, $current)) {
            throw new ApiException(403, 'CHAT_AGENT_REQUIRED');
        }
        if ($current->attemptId !== $execution->attemptId || $current->version !== $execution->version || 'processing' !== $current->status) {
            throw new ApiException(409, 'CHAT_VERSION_CONFLICT');
        }

        return $execution->conversation;
    }

    public function save(Conversation $conversation, bool $supersede = false): void
    {
        $messages = $this->store->load($conversation->id);
        if (!$messages->getMetadata()->has(self::METADATA)) {
            throw new ApiException(404, 'CHAT_NOT_FOUND');
        }
        if ($supersede) {
            $messages = $messages->with(Message::forSystem(''));
        }
        $messages->getMetadata()->add(self::METADATA, $conversation);
        $this->store->save($messages);
        $execution = $this->requests->getCurrentRequest()?->attributes->get(ChatExecution::class);
        if ($execution instanceof ChatExecution && $execution->conversation === $conversation) {
            $execution->version = $conversation->version;
        }
    }

    public function clear(Conversation $conversation): void
    {
        $this->store->delete($conversation->id);
        $this->index->deleteItem(hash('sha256', $conversation->contextKey . $this->indexNamespace()));
    }

    public function append(Conversation $conversation, string $requestId, string $content, bool $failure = false): void
    {
        $message = $failure ? Message::forSystem('') : Message::ofUser($content);
        $message->getMetadata()->set(['requestId' => $requestId, 'createdAt' => $this->clock->now()->format(\DateTimeInterface::ATOM)]);
        if ($failure) {
            $message->getMetadata()->add('failureCode', $content);
        }
        $messages = $this->store->load($conversation->id)->with($message);
        $messages->getMetadata()->add(self::METADATA, $conversation);
        $this->store->save($messages);
    }

    /** @return list<array{id: string, requestId: string, role: string, content: string, createdAt: string, failureCode?: string}> */
    public function messages(Conversation $conversation): array
    {
        $rows = $outcomes = [];
        $requestId = '';
        foreach ($this->store->load($conversation->id) as $message) {
            /** @var array{requestId?: string, createdAt?: string, failureCode?: string} $metadata */
            $metadata = $message->getMetadata()->all();
            if ($message instanceof UserMessage) {
                $requestId = $metadata['requestId'] ?? $message->getId()->toRfc4122();
                $content = $message->asText() ?? '';
            } elseif ($message instanceof AssistantMessage && null !== $message->asText()) {
                $content = $message->asText();
            } elseif ($message instanceof SystemMessage && isset($metadata['failureCode'])) {
                $content = '';
            } else {
                continue;
            }
            $row = ['id'    => $message->getId()->toRfc4122(), 'requestId' => $metadata['requestId'] ?? $requestId,
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

    private function load(string $id): ?Conversation
    {
        $uuid = Uuid::fromString($id);
        if (!$uuid instanceof \Symfony\Component\Uid\UuidV7) {
            return null;
        }
        $value = $this->store->load($uuid)->getMetadata()->get(self::METADATA);

        return $value instanceof Conversation ? $value : null;
    }

    private function indexNamespace(): string
    {
        $session = $this->requests->getMainRequest()?->attributes->get(AuthSession::class);

        return $session instanceof AuthSession ? $session->getId()->toRfc4122() : '';
    }
}
