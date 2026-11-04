<?php

namespace App\Application\TreeMerge\Ai;

use App\Api\ApiException;
use App\Application\TreeMerge\MergeActivity;
use App\Entity\TreeMergeDraft;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final readonly class MergeTokenBudget
{
    public function __construct(#[Target('tree_merge_tokens')] private RateLimiterFactoryInterface $limiter, private MergeActivity $activity)
    {
    }

    public function assertAvailable(TreeMergeDraft $draft): void
    {
        if ($this->limiter->create($draft->owner->getId()->toRfc4122())->consume(0)->getRemainingTokens() <= 0) {
            throw new ApiException(429, 'TREE_MERGE_TOKEN_LIMIT');
        }
    }

    /** @return array{remaining: int, limit: int} */
    public function remaining(User $user): array
    {
        $limit = $this->limiter->create($user->getId()->toRfc4122())->consume(0);

        return ['remaining' => max(0, $limit->getRemainingTokens()), 'limit' => $limit->getLimit()];
    }

    public function record(TreeMergeDraft $draft, int $input, int $output, ?int $total = null): void
    {
        $input = max(0, $input);
        $output = max(0, $output);
        $total = max($input + $output, $total ?? 0);
        $this->activity->tokens($draft, $input, $output, $total);
        $limiter = $this->limiter->create($draft->owner->getId()->toRfc4122());
        $limit = $limiter->consume(0)->getLimit();
        // Record already spent tokens even if an in-flight response crosses the budget. Never wait here.
        while ($total > 0) {
            $chunk = min($total, $limit);
            $limiter->reserve($chunk);
            $total -= $chunk;
        }
    }
}
