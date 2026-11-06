<?php

namespace App\Application\Chat;

use App\Model\Conversation;
use App\Security\ChatVoter;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/** Publishes private chat notifications after rechecking the owning login. */
final readonly class MercureChatEventPublisher implements ChatEventPublisherInterface
{
    public function __construct(
        #[Target('defaultHub')]
        private HubInterface $hub,
        private AuthorizationCheckerInterface $authorization,
        private LoggerInterface $logger,
        #[Autowire('%app.chat_topic_prefix%')]
        private string $topicPrefix,
    ) {
    }

    public function progress(Conversation $conversation): void
    {
        $this->publish(
            $conversation,
            [
                'kind'             => 'progress',
                'id'               => $conversation->id->toRfc4122(),
                'version'          => $conversation->version,
                'attemptId'        => $conversation->attemptId,
                'attemptStartedAt' => $conversation->attemptStartedAt?->format(\DateTimeInterface::ATOM),
                'progress'         => $conversation->progress,
                'tokenUsage'       => $conversation->usage,
            ],
        );
    }

    public function conversation(Conversation $conversation, array $view): void
    {
        if (($view['id'] ?? null) === $conversation->id->toRfc4122()) {
            $this->publish($conversation, ['kind' => 'conversation', 'conversation' => $view]);
        }
    }

    public function removed(Conversation $conversation): void
    {
        $this->publish($conversation, ['kind' => 'removed', 'id' => $conversation->id->toRfc4122()]);
    }

    public function toolApplied(Conversation $conversation, string $tool, string $operationId, array $result): void
    {
        $this->publish(
            $conversation,
            [
                'kind'        => 'tool',
                'id'          => $conversation->id->toRfc4122(),
                'version'     => $conversation->version,
                'tool'        => $tool,
                'action'      => 'applied',
                'operationId' => $operationId,
                'result'      => $result,
            ],
        );
    }

    /** @param array<string, mixed> $payload */
    private function publish(Conversation $conversation, array $payload): void
    {
        try {
            if (!$this->authorization->isGranted(ChatVoter::USE, $conversation)) {
                return;
            }
            $prefix = str_replace('{sessionId}', $conversation->sessionId->toRfc4122(), $this->topicPrefix);
            $this->hub->publish(
                new Update(
                    $prefix . $conversation->id->toRfc4122(),
                    json_encode($payload, JSON_THROW_ON_ERROR),
                    private: true,
                ),
            );
        } catch (\Throwable $error) {
            // Event delivery must not undo a committed application operation.
            $this->logger->warning('Chat event delivery failed: ' . $error::class);
        }
    }
}
