<?php

namespace App\Application\Chat;

use App\Api\ApiException;
use App\Api\ChatViewFactory;
use App\Entity\AuthSession;
use App\Model\Conversation;
use App\Security\ChatExecution;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

/** Orchestrates requests; Symfony AI owns the agent and its complete tool loop. */
final readonly class ConversationService
{
    /** @param ServiceProviderInterface<AgentInterface> $agents */
    public function __construct(
        private Chat $chat, private ClockInterface $clock, private RequestStack $requests, private ChatActivity $activity,
        private ChatEvents $events, private ChatViewFactory $views, private LoggerInterface $logger,
        #[Target('chat')] private RateLimiterFactoryInterface $limiter,
        #[AutowireLocator('ai.agent', indexAttribute: 'name')] private ServiceProviderInterface $agents,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function append(Conversation $conversation, array $payload): Conversation
    {
        if (3 !== count($payload) || !is_int($payload['version'] ?? null) || !is_string($payload['requestId'] ?? null)
            || !preg_match('/^[a-zA-Z0-9-]{1,64}$/D', $payload['requestId']) || !is_string($payload['content'] ?? null)
            || '' === trim($payload['content']) || mb_strlen($payload['content']) > Chat::MAX_INPUT_LENGTH) {
            throw new ApiException(422, 'CHAT_INVALID');
        }

        return $this->chat->synchronized($conversation->contextKey, function () use ($conversation, $payload): Conversation {
            $current = $this->chat->get($conversation->id->toRfc4122());
            $messages = $this->chat->messages($current);
            foreach ($messages as $message) {
                if ('user' === $message['role'] && $payload['requestId'] === $message['requestId']) {
                    if ($message['content'] !== $payload['content']) {
                        throw new ApiException(409, 'CHAT_REQUEST_CONFLICT');
                    }

                    return $current;
                }
            }
            $this->version($current, $payload['version']);
            $length = mb_strlen($payload['content']);
            foreach ($messages as $message) {
                $length += 'user' === $message['role'] ? mb_strlen($message['content']) : 0;
            }
            if ($length > Chat::MAX_INPUT_LENGTH || count($messages) >= 200) {
                throw new ApiException(422, 'CHAT_INPUT_LIMIT');
            }
            $current->invalidateAttempt($this->clock->now());
            $this->chat->append($current, $payload['requestId'], $payload['content']);

            return $current;
        });
    }

    public function process(Conversation $conversation, mixed $version, mixed $requestId): Conversation
    {
        if (!is_int($version) || !is_string($requestId)) {
            throw new ApiException(422, 'CHAT_INVALID');
        }
        $running = $this->chat->synchronized($conversation->contextKey, function () use ($conversation, $version, $requestId): ?Conversation {
            $current = $this->chat->get($conversation->id->toRfc4122());
            $messages = $this->chat->messages($current);
            if (array_any($messages, static fn ($message) => 'assistant' === $message['role'] && $requestId === $message['requestId'])) {
                return null;
            }
            $this->version($current, $version);
            $last = array_find(array_reverse($messages), static fn ($message) => 'user' === $message['role']);
            if (null === $last || $last['requestId'] !== $requestId) {
                throw new ApiException(409, 'CHAT_VERSION_CONFLICT');
            }
            if ('processing' === $current->status && $current->attemptStartedAt > $this->clock->now()->modify('-5 minutes')) {
                throw new ApiException(409, 'CHAT_PROCESSING');
            }
            if (!$this->limiter->create($current->userId->toRfc4122())->consume()->isAccepted()) {
                throw new ApiException(429, 'CHAT_RATE_LIMIT');
            }
            $current->beginAttempt($this->clock->now(), $requestId);
            $this->chat->save($current);

            return $current;
        });
        if (null === $running) {
            return $this->chat->get($conversation->id->toRfc4122());
        }
        $request = $this->requests->getMainRequest() ?? throw new ApiException(401, 'AUTH_REQUIRED');
        $previous = $request->attributes->get(ChatExecution::class);
        $previousConversation = $request->attributes->get(Conversation::class);
        $execution = new ChatExecution($running, $running->attemptId ?? throw new \LogicException('Missing attempt'));
        $request->attributes->set(ChatExecution::class, $execution);
        $request->attributes->set(Conversation::class, $running);
        $this->events->begin($running);
        try {
            $answer = null;
            $failure = 'empty_response';
            try {
                $this->chat->active();
                $this->activity->step($running, 'thinking');
                $agent = $this->agents->get($running->profile);
                if (!$agent instanceof AgentInterface) {
                    throw new \LogicException('Invalid chat agent');
                }
                $text = $agent->call(MessageBag::forId($running->id))->asText();
                if ('' !== trim($text)) {
                    $answer = $text;
                }
            } catch (\Throwable $error) {
                $this->logger->warning('Chat agent failed: ' . $error::class);
                $failure = $error instanceof ApiException && 'CHAT_TOKEN_LIMIT' === $error->getErrorCode() ? 'token_limit' : 'provider_unavailable';
            }
            $this->chat->synchronized($running->contextKey, function () use ($running, $answer, $failure, $requestId): void {
                try {
                    $this->chat->active();
                } catch (ApiException) {
                    return;
                }
                $running->finishAttempt($this->clock->now(), null === $answer);
                if (null === $answer) {
                    $this->chat->append($running, $requestId, $failure, true);
                } else {
                    $this->chat->save($running);
                }
            });
            $current = $this->chat->get($running->id->toRfc4122());
            $session = $request->attributes->get(AuthSession::class);
            if ($session instanceof AuthSession) {
                $this->events->conversation($session, $this->views->create($current));
            }

            return $current;
        } finally {
            foreach ([ChatExecution::class => $previous, Conversation::class => $previousConversation] as $key => $value) {
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

        return $this->chat->synchronized($conversation->contextKey, function () use ($conversation, $requestId): Conversation {
            $current = $this->chat->get($conversation->id->toRfc4122());
            $messages = $this->chat->messages($current);
            $last = array_find(array_reverse($messages), static fn ($message) => 'user' === $message['role']);
            if (null === $last || $last['requestId'] !== $requestId) {
                throw new ApiException(409, 'CHAT_VERSION_CONFLICT');
            }
            if (array_any($messages, static fn ($message) => 'assistant' === $message['role'] && $message['requestId'] === $requestId)) {
                return $current;
            }
            $this->activity->stop($current, $requestId);
            $current->finishAttempt($this->clock->now());
            $this->chat->append($current, $requestId, 'stopped', true);

            return $current;
        });
    }

    public function reset(Conversation $conversation, mixed $version): void
    {
        $this->chat->synchronized($conversation->contextKey, function () use ($conversation, $version): void {
            $current = $this->chat->get($conversation->id->toRfc4122());
            $this->version($current, $version);
            $this->chat->clear($current);
        });
        $session = $this->requests->getMainRequest()?->attributes->get(AuthSession::class);
        if ($session instanceof AuthSession) {
            $this->events->removed($session, $conversation->id->toRfc4122());
        }
    }

    private function version(Conversation $conversation, mixed $version): void
    {
        if ($conversation->version !== $version) {
            throw new ApiException(409, 'CHAT_VERSION_CONFLICT');
        }
    }
}
