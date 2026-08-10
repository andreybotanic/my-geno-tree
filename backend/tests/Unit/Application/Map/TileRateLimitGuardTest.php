<?php

namespace App\Tests\Unit\Application\Map;

use App\Application\Map\TileRateLimitGuard;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class TileRateLimitGuardTest extends TestCase
{
    public function testItUsesOneGlobalBucketAndAUserSpecificBucket(): void
    {
        $now = new \DateTimeImmutable('2026-08-10T12:00:00+00:00');
        $user = new User('map_user', 'map@example.test', 'hash', 'en', $now);
        $accepted = new RateLimit(24, $now, true, 25);
        $userFactory = $this->factoryExpectingKey(
            $user->getId()->toRfc4122(),
            $this->limiterReturning($accepted),
        );
        $globalFactory = $this->factoryExpectingKey(
            'yandex-tiles-api-key',
            $this->limiterReturning($accepted),
        );

        (new TileRateLimitGuard(
            $globalFactory,
            $userFactory,
            new MockClock($now),
        ))->consume($user);
    }

    public function testRejectedUserLimitDoesNotConsumeTheGlobalBucket(): void
    {
        $now = new \DateTimeImmutable('2026-08-10T12:00:00+00:00');
        $user = new User('limited_user', 'limited@example.test', 'hash', 'en', $now);
        $rejected = new RateLimit(0, $now->modify('+17 seconds'), false, 180);
        $userFactory = $this->factoryExpectingKey(
            $user->getId()->toRfc4122(),
            $this->limiterReturning($rejected),
        );
        $globalFactory = $this->createMock(RateLimiterFactoryInterface::class);
        $globalFactory->expects($this->never())->method('create');
        $guard = new TileRateLimitGuard(
            $globalFactory,
            $userFactory,
            new MockClock($now),
        );

        try {
            $guard->consume($user);
            self::fail('Expected the user rate limit to reject the request.');
        } catch (TooManyRequestsHttpException $exception) {
            self::assertSame(17, $exception->getHeaders()['Retry-After']);
            self::assertSame('180', $exception->getHeaders()['X-RateLimit-Limit']);
            self::assertSame('0', $exception->getHeaders()['X-RateLimit-Remaining']);
        }
    }

    private function factoryExpectingKey(
        string $key,
        LimiterInterface $limiter,
    ): RateLimiterFactoryInterface {
        $factory = $this->createMock(RateLimiterFactoryInterface::class);
        $factory
            ->expects($this->once())
            ->method('create')
            ->with($key)
            ->willReturn($limiter);

        return $factory;
    }

    private function limiterReturning(RateLimit $limit): LimiterInterface
    {
        $limiter = $this->createMock(LimiterInterface::class);
        $limiter
            ->expects($this->once())
            ->method('consume')
            ->with(1)
            ->willReturn($limit);

        return $limiter;
    }
}
