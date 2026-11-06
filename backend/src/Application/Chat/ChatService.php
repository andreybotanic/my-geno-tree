<?php

namespace App\Application\Chat;

use App\Api\ApiException;
use App\Api\ConversationNormalizer;
use App\Entity\AuthSession;
use App\Model\Conversation;
use App\Security\ChatExecution;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ServiceProviderInterface;

/** Application use cases; the configured Symfony agent owns inference and the complete tool loop. */
final readonly class ChatService implements ChatInterface
{
    private const int MAX_INPUT_LENGTH = 10000;

    /** @param ServiceProviderInterface<AgentInterface> $agents */
    public function __construct(
        private GuardedMessageStore $store,
        private ConversationNormalizer $views,
        private MercureChatTransport $events,
        private ClockInterface $clock,
        private RequestStack $requests,
        private LoggerInterface $logger,
        #[Target('chat')]
        private RateLimiterFactoryInterface $limiter,
        #[AutowireLocator('ai.agent', indexAttribute: 'name')]
        private ServiceProviderInterface $agents,
    ) {
    }

    public function open(string $contextKey, string $profile, array $context): Conversation
    {
        return $this->store->open($contextKey, $profile, $context);
    }

    public function current(string $contextKey): ?Conversation
    {
        return $this->store->current($contextKey);
    }

    public function get(string $id): Conversation
    {
        return $this->store->get($id);
    }

    public function send(Conversation $conversation, array $payload): Conversation
    {
        if (3 !== count($payload) || !is_int($payload['version'] ?? null) || !is_string($payload['requestId'] ?? null)
            || !preg_match('/^[a-zA-Z0-9-]{1,64}$/D', $payload['requestId']) || !is_string($payload['content'] ?? null)
            || '' === trim($payload['content']) || mb_strlen($payload['content']) > self::MAX_INPUT_LENGTH) {
            throw new ApiException(422, 'CHAT_INVALID');
        }
        $running = $this->store->synchronized(
            $conversation->contextKey,
            function () use ($conversation, $payload): ?Conversation {
                $current = $this->get($conversation->id->toRfc4122());
                $messages = $this->messages($current);
                $existing = array_find($messages, static fn ($message) => 'user' === $message['role'] && $payload['requestId'] === $message['requestId']);
                if (null !== $existing && $existing['content'] !== $payload['content']) {
                    throw new ApiException(409, 'CHAT_REQUEST_CONFLICT');
                }
                if (array_any($messages, static fn ($message) => 'assistant' === $message['role'] && $payload['requestId'] === $message['requestId'])) {
                    return null;
                }
                if (null === $existing) {
                    $this->version($current, $payload['version']);
                    $length = mb_strlen($payload['content']) + array_sum(array_map(static fn ($message) => 'user' === $message['role'] ? mb_strlen($message['content']) : 0, $messages));
                    if ($length > self::MAX_INPUT_LENGTH || count($messages) >= 200) {
                        throw new ApiException(422, 'CHAT_INPUT_LIMIT');
                    }
                } else {
                    $last = array_find(array_reverse($messages), static fn ($message) => 'user' === $message['role']);
                    if (null === $last || $last['requestId'] !== $payload['requestId']) {
                        throw new ApiException(409, 'CHAT_VERSION_CONFLICT');
                    }
                }
                if ('processing' === $current->status && $current->attemptStartedAt > $this->clock->now()->modify('-5 minutes')) {
                    throw new ApiException(409, 'CHAT_PROCESSING');
                }
                if (!$this->limiter->create($current->userId->toRfc4122())->consume()->isAccepted()) {
                    throw new ApiException(429, 'CHAT_RATE_LIMIT');
                }
                $current->beginAttempt($this->clock->now(), $payload['requestId']);
                if (null === $existing) {
                    $this->append($current, $payload['requestId'], $payload['content']);
                } else {
                    $this->store->persist($current);
                }

                return $current;
            }
        );
        if (null === $running) {
            return $this->get($conversation->id->toRfc4122());
        }
        $this->publish($running);
        $request = $this->requests->getMainRequest() ?? throw new ApiException(401, 'AUTH_REQUIRED');
        $previous = $request->attributes->get(ChatExecution::class);
        $previousState = $request->attributes->get(Conversation::class);
        $execution = new ChatExecution($running, $running->attemptId ?? throw new \LogicException('Missing attempt'));
        $request->attributes->set(ChatExecution::class, $execution);
        $request->attributes->set(Conversation::class, $running);
        try {
            $answered = false;
            $failure = 'empty_response';
            try {
                $agent = $this->agents->get($running->profile);
                $execution->native = $agent->call(MessageBag::forId($running->id));
                $execution->native->onProgress(fn (Progress $progress) => $this->progress($progress));
                $answered = '' !== trim($execution->native->asText());
            } catch (\Throwable $error) {
                $this->logger->warning('Chat agent failed: ' . $error::class);
                $failure = $error instanceof ApiException && 'CHAT_TOKEN_LIMIT' === $error->getErrorCode() ? 'token_limit' : 'provider_unavailable';
            }
            $this->store->synchronized(
                $running->contextKey,
                function () use ($running, $answered, $failure, $payload): void {
                    try {
                        $this->execution();
                    } catch (ApiException) {
                        return;
                    }
                    $running->finishAttempt($this->clock->now(), !$answered);
                    if ($answered) {
                        $this->store->persist($running);
                    } else {
                        $this->append($running, $payload['requestId'], $failure, true);
                    }
                }
            );
            $current = $this->get($running->id->toRfc4122());
            $this->publish($current);

            return $current;
        } finally {
            foreach ([ChatExecution::class => $previous, Conversation::class => $previousState] as $key => $value) {
                if (null === $value) {
                    $request->attributes->remove($key);
                } else {
                    $request->attributes->set($key, $value);
                }
            }
        }
    }

    public function stop(Conversation $conversation, mixed $requestId): Conversation
    {
        if (!is_string($requestId) || '' === $requestId) {
            throw new ApiException(422, 'CHAT_INVALID');
        }
        $current = $this->store->synchronized(
            $conversation->contextKey,
            function () use ($conversation, $requestId): Conversation {
                $current = $this->get($conversation->id->toRfc4122());
                $messages = $this->messages($current);
                $last = array_find(array_reverse($messages), static fn ($message) => 'user' === $message['role']);
                if (null === $last || $last['requestId'] !== $requestId) {
                    throw new ApiException(409, 'CHAT_VERSION_CONFLICT');
                }
                if (!array_any($messages, static fn ($message) => 'assistant' === $message['role'] && $message['requestId'] === $requestId)) {
                    $current->finishAttempt($this->clock->now());
                    $this->append($current, $requestId, 'stopped', true);
                }

                return $current;
            }
        );
        $this->publish($current);

        return $current;
    }

    public function reset(Conversation $conversation, mixed $version): void
    {
        $this->store->synchronized(
            $conversation->contextKey,
            function () use ($conversation, $version): void {
                $current = $this->get($conversation->id->toRfc4122());
                $this->version($current, $version);
                $this->store->delete($current->id);
            }
        );
        $session = $this->requests->getMainRequest()?->attributes->get(AuthSession::class);
        if ($session instanceof AuthSession) {
            $this->events->removed($session, $conversation->id->toRfc4122());
        }
    }

    public function execution(): ChatExecution
    {
        return $this->store->execution();
    }

    public function replaceTool(Conversation $conversation, string $name, ?object $state, bool $invalidate = true): Conversation
    {
        $current = $this->store->synchronized(
            $conversation->contextKey,
            function () use ($conversation, $name, $state, $invalidate): Conversation {
                $current = $this->get($conversation->id->toRfc4122());
                $this->version($current, $conversation->version);
                $execution = $this->requests->getMainRequest()?->attributes->get(ChatExecution::class);
                if (!$invalidate) {
                    $this->execution();
                    $current = $execution instanceof ChatExecution ? $execution->conversation : $current;
                }
                if (null === $state) {
                    unset($current->tools[$name]);
                } else {
                    $current->tools[$name] = $state;
                }
                $current->toolVersions[$name] = ($current->toolVersions[$name] ?? 0) + 1;
                if ($invalidate) {
                    $current->invalidateAttempt($this->clock->now());
                } else {
                    $current->changed($this->clock->now());
                }
                $this->store->persist($current);

                return $current;
            }
        );
        $this->publish($current);

        return $current;
    }

    public function snapshot(Conversation $conversation): array
    {
        return $this->views->normalize($conversation);
    }

    public function messages(Conversation $conversation): array
    {
        return $this->views->messages($conversation);
    }

    public function budget(Uuid $user): array
    {
        return $this->views->budget($user);
    }

    public function toolApplied(Conversation $conversation, string $name, string $operationId, array $result): void
    {
        $session = $this->requests->getMainRequest()?->attributes->get(AuthSession::class);
        if ($session instanceof AuthSession) {
            $this->events->tool($session, $conversation, $name, 'applied', $operationId, $result);
        }
    }

    private function progress(Progress $progress): void
    {
        $current = $this->execution()->conversation;
        $this->store->synchronized(
            $current->contextKey,
            function () use ($current, $progress): void {
                $this->execution();
                $payload = $progress->getPayload();
                $stage = $payload instanceof ToolCall ? $payload->getName() : $progress->getStage();
                if (!in_array($stage, $current->progress, true)) {
                    $current->progress[] = $stage;
                    $this->store->persist($current);
                    $this->events->progress($current);
                }
            }
        );
    }

    private function publish(Conversation $conversation): void
    {
        $session = $this->requests->getMainRequest()?->attributes->get(AuthSession::class);
        if ($session instanceof AuthSession) {
            $this->events->conversation($session, $this->snapshot($conversation));
        }
    }

    private function append(Conversation $conversation, string $requestId, string $content, bool $failure = false): void
    {
        $message = $failure ? Message::forSystem('') : Message::ofUser($content);
        $message->getMetadata()->set(['requestId' => $requestId, 'createdAt' => $this->clock->now()->format(\DateTimeInterface::ATOM)]);
        if ($failure) {
            $message->getMetadata()->add('failureCode', $content);
        }
        $messages = $this->store->load($conversation->id)->with($message);
        $messages->getMetadata()->add(Conversation::METADATA, $conversation);
        $this->store->save($messages);
    }

    private function version(Conversation $conversation, mixed $version): void
    {
        if ($conversation->version !== $version) {
            throw new ApiException(409, 'CHAT_VERSION_CONFLICT');
        }
    }
}
