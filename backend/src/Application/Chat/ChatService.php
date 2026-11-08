<?php

namespace App\Application\Chat;

use App\Api\ApiException;
use App\Api\ConversationNormalizer;
use App\Application\Chat\Moderation\ChatModeration;
use App\Application\Chat\Moderation\ModerationWarning;
use App\Application\Tree\TreeActionService;
use App\Model\Conversation;
use App\Model\ToolConfirmation;
use App\Security\ChatExecution;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Exception\MaxIterationsExceededException;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Platform\Exception\ContentFilterException;
use Symfony\AI\Platform\Exception\ExceedContextSizeException;
use Symfony\AI\Platform\Exception\MaxOutputTokensException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Result\Stream\Delta\CommentaryComplete;
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
        private ChatEventPublisherInterface $events,
        private ClockInterface $clock,
        private RequestStack $requests,
        private LoggerInterface $logger,
        #[Target('chat')]
        private RateLimiterFactoryInterface $limiter,
        #[AutowireLocator('ai.agent', indexAttribute: 'name')]
        private ServiceProviderInterface $agents,
        private ChatModeration $moderation,
        private TreeActionService $actions,
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
                $existing = array_find(
                    $messages,
                    static fn ($message) => 'user' === $message['role']
                        && $payload['requestId'] === $message['requestId'],
                );
                if (null !== $existing && $existing['content'] !== $payload['content']) {
                    throw new ApiException(409, 'CHAT_REQUEST_CONFLICT');
                }
                if (array_any(
                    $messages,
                    static fn ($message) => ('assistant' === $message['role']
                        || 'policy_warning' === ($message['failureCode'] ?? null))
                        && $payload['requestId'] === $message['requestId'],
                )) {
                    return null;
                }
                $this->moderation->assertCanInteract($current->userId);
                if (null === $existing) {
                    $this->version($current, $payload['version']);
                    $length = mb_strlen($payload['content']) + array_sum(
                        array_map(
                            static fn ($message) => 'user' === $message['role'] ? mb_strlen($message['content']) : 0,
                            $messages,
                        ),
                    );
                    if ($length > self::MAX_INPUT_LENGTH || count($messages) >= 200) {
                        throw new ApiException(422, 'CHAT_INPUT_LIMIT');
                    }
                } else {
                    $last = array_find(array_reverse($messages), static fn ($message) => 'user' === $message['role']);
                    if (null === $last || $last['requestId'] !== $payload['requestId']) {
                        throw new ApiException(409, 'CHAT_VERSION_CONFLICT');
                    }
                }
                if ('processing' === $current->status
                    && $current->attemptStartedAt > $this->clock->now()->modify('-5 minutes')) {
                    throw new ApiException(409, 'CHAT_PROCESSING');
                }
                if (!$this->limiter->create($current->userId->toRfc4122())->consume()->isAccepted()) {
                    throw new ApiException(429, 'CHAT_RATE_LIMIT');
                }
                if ('awaiting_confirmation' === $current->status && null !== $existing) {
                    return null;
                }
                $this->cancelPending($current);
                $current->beginAttempt($this->clock->now(), $payload['requestId']);
                if (null === $existing) {
                    $this->append($current, $payload['requestId'], $payload['content']);
                } else {
                    $this->store->persist($current);
                }

                return $current;
            },
        );
        if (null === $running) {
            return $this->get($conversation->id->toRfc4122());
        }

        return $this->run($running);
    }

    private function run(
        Conversation $running,
        ?ToolConfirmation $confirmation = null,
        bool $approve = false,
    ): Conversation {
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
            $warning = null;
            $accessError = null;
            try {
                if (null !== $confirmation) {
                    $this->store->synchronized(
                        $running->contextKey,
                        function () use ($running, $confirmation, $approve): void {
                            $this->store->execution();
                            $result = $approve
                                ? $this->actions->execute(
                                    $confirmation->call->getName(),
                                    $confirmation->call->getArguments(),
                                )
                                : ['error' => ['code' => 'TOOL_DENIED']];
                            $confirmation->result = $result;
                            $confirmation->status = match (true) {
                                !$approve               => 'rejected',
                                isset($result['error']) => 'failed',
                                default                 => 'applied',
                            };
                            $this->replaceToolResult($running, $confirmation, $result);
                            if (isset($result['etag'])) {
                                $this->events->toolApplied(
                                    $running,
                                    $confirmation->call->getName(),
                                    $confirmation->call->getId(),
                                    $result,
                                );
                            }
                        },
                    );
                }
                $agent = $this->agents->get($running->profile);
                $execution->native = $agent->call(MessageBag::forId($running->id));
                $execution->native->onProgress(fn (Progress $progress) => $this->progress($progress));
                $answered = '' !== trim($execution->native->asText());
            } catch (ModerationWarning $notice) {
                $failure = 'policy_warning';
                $warning = $notice->number;
            } catch (\Throwable $error) {
                $this->logger->warning('Chat agent failed: ' . $error::class, ['exception' => $error]);
                if ($error instanceof ApiException
                    && in_array($error->getErrorCode(), ['CHAT_UNAVAILABLE', 'CHAT_BANNED'], true)) {
                    $accessError = $error;
                }
                $failure = match (true) {
                    $error instanceof ApiException => match ($error->getErrorCode()) {
                        'CHAT_TOKEN_LIMIT' => 'token_limit',
                        'CHAT_UNAVAILABLE' => 'chat_unavailable',
                        'CHAT_BANNED'      => 'chat_banned',
                        default            => 'provider_unavailable',
                    },
                    $error instanceof RateLimitExceededException     => 'provider_rate_limit',
                    $error instanceof ExceedContextSizeException     => 'context_limit',
                    $error instanceof MaxOutputTokensException       => 'output_limit',
                    $error instanceof MaxIterationsExceededException => 'tool_limit',
                    $error instanceof ContentFilterException         => 'provider_refused',
                    default                                          => 'provider_unavailable',
                };
            }
            $this->store->synchronized(
                $running->contextKey,
                function () use ($running, $answered, $failure, $warning, $execution): void {
                    try {
                        $this->store->execution(allowBlocked: true);
                    } catch (ApiException) {
                        return;
                    }
                    $finishedAt = $this->clock->now();
                    if ($execution->paused) {
                        $running->status = 'awaiting_confirmation';
                        $running->changed($finishedAt);
                        $this->store->persist($running);

                        return;
                    }
                    $running->finishAttempt($finishedAt, !$answered && null === $warning);
                    if ($answered) {
                        $messages = $this->store->load($running->id);
                        $answer = array_last($messages->getMessages());
                        if ($answer instanceof AssistantMessage && null !== $answer->asText()) {
                            $startedAt = $running->attemptStartedAt ?? $finishedAt;
                            $duration = (float)$finishedAt->format('U.u') - (float)$startedAt->format('U.u');
                            $answer
                                ->getMetadata()
                                ->add(
                                    'responseMetrics',
                                    [
                                        'durationMs' => max(0, (int)round($duration * 1000)),
                                        'tokenUsage' => $running->usage,
                                    ],
                                );
                        }
                        $messages->getMetadata()->add(Conversation::METADATA, $running);
                        $this->store->save($messages);
                    } else {
                        $this->append($running, $running->attemptRequestId ?? '', $failure, true, $warning);
                    }
                }
            );
            $current = $this->get($running->id->toRfc4122());
            $this->publish($current);

            if (null !== $accessError) {
                throw $accessError;
            }

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
                if (!array_any(
                    $messages,
                    static fn ($message) => 'assistant' === $message['role'] && $message['requestId'] === $requestId,
                )) {
                    $this->cancelPending($current);
                    $current->finishAttempt($this->clock->now());
                    $this->append($current, $requestId, 'stopped', true);
                }

                return $current;
            },
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
        $this->events->removed($conversation);
    }

    public function execution(): ChatExecution
    {
        return $this->store->execution();
    }

    public function decide(Conversation $conversation, string $id, array $payload): Conversation
    {
        if (array_diff(array_keys($payload), ['version', 'decision', 'autoApprovePersonActions'])
            || !is_int($payload['version'] ?? null)
            || !in_array($payload['decision'] ?? null, ['approve', 'reject'], true)
            || (isset($payload['autoApprovePersonActions']) && !is_bool($payload['autoApprovePersonActions']))) {
            throw new ApiException(422, 'CHAT_INVALID');
        }
        $claimed = $this->store->synchronized(
            $conversation->contextKey,
            function () use ($conversation, $id, $payload): ?array {
                $current = $this->get($conversation->id->toRfc4122());
                $this->moderation->assertCanInteract($current->userId);
                $confirmation = array_find($current->confirmations, static fn ($row) => $row->id === $id);
                if (null === $confirmation) {
                    throw new ApiException(404, 'CONFIRMATION_NOT_FOUND');
                }
                if ('pending' !== $confirmation->status) {
                    return null;
                }
                $this->version($current, $payload['version']);
                if ('awaiting_confirmation' !== $current->status) {
                    throw new ApiException(409, 'CHAT_PROCESSING');
                }
                $approve = 'approve' === $payload['decision'];
                if (isset($payload['autoApprovePersonActions']) && $approve) {
                    $current->autoApprovePersonActions = true === $payload['autoApprovePersonActions'];
                }
                $usage = $current->usage;
                $current->beginAttempt($this->clock->now(), $confirmation->requestId);
                $current->usage = $usage;
                $confirmation->status = 'executing';
                $this->store->persist($current);

                return [$current, $confirmation];
            },
        );

        return null === $claimed ? $this->get($conversation->id->toRfc4122())
            : $this->run($claimed[0], $claimed[1], 'approve' === $payload['decision']);
    }

    public function permissions(Conversation $conversation, array $payload): Conversation
    {
        if (2 !== count($payload) || !is_int($payload['version'] ?? null)
            || !is_bool($payload['autoApprovePersonActions'] ?? null)) {
            throw new ApiException(422, 'CHAT_INVALID');
        }
        $current = $this->store->synchronized(
            $conversation->contextKey,
            function () use ($conversation, $payload): Conversation {
                $current = $this->get($conversation->id->toRfc4122());
                $this->moderation->assertCanInteract($current->userId);
                $this->version($current, $payload['version']);
                if ('processing' === $current->status) {
                    throw new ApiException(409, 'CHAT_PROCESSING');
                }
                $current->autoApprovePersonActions = true === $payload['autoApprovePersonActions'];
                $current->changed($this->clock->now());
                $this->store->persist($current);

                return $current;
            },
        );
        $this->publish($current);

        return $current;
    }

    private function cancelPending(Conversation $conversation): void
    {
        foreach ($conversation->confirmations as $confirmation) {
            if ('pending' === $confirmation->status) {
                $confirmation->status = 'cancelled';
                $confirmation->result = ['error' => ['code' => 'TOOL_CANCELLED']];
                $this->replaceToolResult($conversation, $confirmation, $confirmation->result);
            }
        }
    }

    /** @param array<string, mixed> $result */
    private function replaceToolResult(Conversation $conversation, ToolConfirmation $confirmation, array $result): void
    {
        $stored = $this->store->load($conversation->id);
        $messages = MessageBag::forId($conversation->id);
        foreach ($stored as $message) {
            if ($message instanceof ToolCallMessage
                && $message->getToolCall()->getId() === $confirmation->call->getId()) {
                $message = Message::ofToolCall(
                    $confirmation->call,
                    json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                )->withId($message->getId());
            }
            $messages = $messages->with($message);
        }
        $messages->getMetadata()->set($stored->getMetadata()->all());
        $messages->getMetadata()->add(Conversation::METADATA, $conversation);
        $this->store->save($messages);
        $this->store->persist($conversation);
    }

    public function snapshot(Conversation $conversation): array
    {
        return [
            ...$this->views->normalize($conversation),
            'access' => $this->access($conversation->userId),
        ];
    }

    public function messages(Conversation $conversation): array
    {
        return $this->views->messages($conversation);
    }

    public function budget(Uuid $user): array
    {
        return $this->views->budget($user);
    }

    public function access(Uuid $user): array
    {
        return $this->moderation->access($user);
    }

    private function progress(Progress $progress): void
    {
        $payload = $progress->getPayload();
        if (!$payload instanceof CommentaryComplete || '' === trim($payload->getCommentary())) {
            return;
        }
        $current = $this->execution()->conversation;
        $this->store->synchronized(
            $current->contextKey,
            function () use ($current, $payload): void {
                $this->execution();
                $current->commentary = $payload->getCommentary();
                ++$current->progressSequence;
                $this->store->persist($current);
                $this->events->progress($current);
            }
        );
    }

    private function publish(Conversation $conversation): void
    {
        $this->events->conversation($conversation, $this->snapshot($conversation));
    }

    private function append(
        Conversation $conversation,
        string $requestId,
        string $content,
        bool $failure = false,
        ?int $warning = null,
    ): void {
        $message = $failure ? Message::forSystem('') : Message::ofUser($content);
        $message->getMetadata()->set(
            ['requestId' => $requestId, 'createdAt' => $this->clock->now()->format(\DateTimeInterface::ATOM)],
        );
        if ($failure) {
            $message->getMetadata()->add('failureCode', $content);
            if (null !== $warning) {
                $message->getMetadata()->add('warningNumber', $warning);
            }
        } elseif ('tree' === $conversation->profile) {
            $message->getMetadata()->add('moderationAllowed', false);
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
