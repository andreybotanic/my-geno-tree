<?php

namespace App\Application\Map;

use App\Entity\User;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final readonly class TileRateLimitGuard
{
    public function __construct(
        #[Target('yandex_tiles_global')]
        private RateLimiterFactoryInterface $globalLimiter,
        #[Target('yandex_tiles_user')]
        private RateLimiterFactoryInterface $userLimiter,
        private ClockInterface $clock,
    ) {
    }

    public function consume(User $user): void
    {
        $this->ensureAccepted(
            $this->userLimiter->create($user->getId()->toRfc4122())->consume(),
        );
        $this->ensureAccepted(
            $this->globalLimiter->create('yandex-tiles-api-key')->consume(),
        );
    }

    private function ensureAccepted(RateLimit $limit): void
    {
        if ($limit->isAccepted()) {
            return;
        }
        $retryAfter = max(
            1,
            $limit->getRetryAfter()->getTimestamp()
                - $this->clock->now()->getTimestamp(),
        );

        throw new TooManyRequestsHttpException($retryAfter, 'Map tile signature rate limit exceeded.', headers: ['X-RateLimit-Limit' => (string)$limit->getLimit(), 'X-RateLimit-Remaining' => (string)$limit->getRemainingTokens()]);
    }
}
