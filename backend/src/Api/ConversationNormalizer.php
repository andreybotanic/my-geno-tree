<?php

namespace App\Api;

use App\Model\Conversation;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ConversationNormalizer implements NormalizerInterface
{
    public function __construct(#[Target('chat')] private MessageStoreInterface $store,
        #[Target('chat_tokens')] private RateLimiterFactoryInterface $tokens)
    {
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Conversation;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Conversation::class => true];
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        if (!$data instanceof Conversation) {
            throw new \InvalidArgumentException('Expected a conversation');
        }

        return [
            'id'               => $data->id->toRfc4122(),
            'profile'          => $data->profile,
            'version'          => $data->version,
            'status'           => $data->status,
            'attemptId'        => $data->attemptId,
            'attemptStartedAt' => $data->attemptStartedAt?->format(\DateTimeInterface::ATOM),
            'messages'         => $this->messages($data),
            'tools'            => (object)$data->tools,
            'progress'         => $data->progress,
            'tokenUsage'       => $data->usage,
            'tokenBudget'      => $this->budget($data->userId),
            'createdAt'        => $data->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt'        => $data->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array{remaining: int, limit: int} */
    public function budget(Uuid $user): array
    {
        $limit = $this->tokens->create($user->toRfc4122())->consume(0);

        return ['remaining' => max(0, $limit->getRemainingTokens()), 'limit' => $limit->getLimit()];
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
            $row = [
                'id'        => $message->getId()->toRfc4122(),
                'requestId' => $metadata['requestId'] ?? $requestId,
                'role'      => $message->getRole()->value,
                'content'   => $content,
                'createdAt' => $metadata['createdAt'] ?? $message->getId()->getDateTime()->format(\DateTimeInterface::ATOM),
            ];
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
