<?php

namespace App\Application\Chat;

use App\Api\ApiException;
use App\Model\Conversation;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ChatTokenBudget
{
    public function __construct(#[Target('chat_tokens')] private RateLimiterFactoryInterface $limiter, private ChatActivity $activity)
    {
    }

    public function assertAvailable(Conversation $conversation): void
    {
        if ($this->limiter->create($conversation->userId->toRfc4122())->consume(0)->getRemainingTokens() <= 0) {
            throw new ApiException(429, 'CHAT_TOKEN_LIMIT');
        }
    }

    /** @return array{remaining: int, limit: int} */
    public function remaining(Uuid $user): array
    {
        $limit = $this->limiter->create($user->toRfc4122())->consume(0);

        return ['remaining' => max(0, $limit->getRemainingTokens()), 'limit' => $limit->getLimit()];
    }

    public function record(Conversation $conversation, int $input, int $output, ?int $total = null): void
    {
        $input = max(0, $input);
        $output = max(0, $output);
        $total = max($input + $output, $total ?? 0);
        $this->activity->tokens($conversation, $input, $output, $total);
        $limiter = $this->limiter->create($conversation->userId->toRfc4122());
        $limit = $limiter->consume(0)->getLimit();
        // Record already spent tokens even if an in-flight response crosses the budget. Never wait here.
        while ($total > 0) {
            $chunk = min($total, $limit);
            $limiter->reserve($chunk);
            $total -= $chunk;
        }
    }
}
