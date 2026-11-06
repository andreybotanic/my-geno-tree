<?php

namespace App\Tests\Unit\Application\Chat;

use App\Application\Chat\Moderation\ModerationPolicy;
use App\Entity\ChatBan;
use App\Entity\SuspiciousChatMessage;
use App\Entity\User;
use App\Schedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Uid\UuidV7;

final class ModerationPolicyTest extends TestCase
{
    /** @return iterable<string, array{int, int, int, float, float, int}> */
    public static function invalidPolicies(): iterable
    {
        yield 'zero duration' => [0, 50000, 2, 0.5, 0.9, 30];
        yield 'negative penalty' => [3600, -1, 2, 0.5, 0.9, 30];
        yield 'negative count' => [3600, 50000, -1, 0.5, 0.9, 30];
        yield 'zero audit threshold' => [3600, 50000, 2, 0, 0.9, 30];
        yield 'reversed thresholds' => [3600, 50000, 2, 0.91, 0.9, 30];
        yield 'warning above one' => [3600, 50000, 2, 0.5, 1.1, 30];
        yield 'nonfinite' => [3600, 50000, 2, NAN, 0.9, 30];
        yield 'no retention' => [3600, 50000, 2, 0.5, 0.9, 0];
    }

    #[DataProvider('invalidPolicies')]
    public function testRejectsInconsistentConfiguration(
        int $seconds,
        int $penalty,
        int $bans,
        float $audit,
        float $warning,
        int $days,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        new ModerationPolicy($seconds, $penalty, $bans, $audit, $warning, $days);
    }

    public function testBanExpiryIsFixedAndNullMeansPermanentOnlyWhenABanExists(): void
    {
        $now = new \DateTimeImmutable('2026-11-06T10:00:00Z');
        $user = new User('owner', 'owner@example.test', 'hash', 'ru', $now);
        self::assertFalse($user->hasChatBan($now));
        self::assertNull($user->getLastChatBan());
        self::assertNull($user->warnAboutChat($now, 3600, 2));
        self::assertNull($user->warnAboutChat($now, 3600, 2));
        $ban = $user->warnAboutChat($now, 3600, 2);
        self::assertInstanceOf(ChatBan::class, $ban);
        self::assertInstanceOf(UuidV7::class, $ban->getId());
        self::assertSame($user, $ban->getUser());
        self::assertSame($now, $ban->getStartsAt());
        self::assertEquals($now->modify('+1 hour'), $ban->getEndsAt());
        self::assertTrue($user->hasChatBan($now->modify('+3599 seconds')));
        self::assertFalse($user->hasChatBan($now->modify('+1 hour')));
        $ban->chargeTokens(123);
        self::assertSame(123, $ban->getChargedTokens());
        $audit = new SuspiciousChatMessage($user, str_repeat('a', 64), 'original text', 0.9, 3, $now);
        self::assertSame($user, $audit->getUser());
        self::assertSame('original text', $audit->getContent());
        self::assertSame($now, $audit->getCreatedAt());
        $this->expectException(\LogicException::class);
        $user->warnAboutChat($now, 1, 0);
    }

    public function testZeroTemporaryBansMakesTheFirstBanPermanent(): void
    {
        $now = new \DateTimeImmutable();
        $user = new User('owner', 'owner@example.test', 'hash', 'en', $now);
        $user->warnAboutChat($now, 3600, 0);
        $user->warnAboutChat($now, 3600, 0);
        $ban = $user->warnAboutChat($now, 3600, 0);
        self::assertNotNull($ban);
        self::assertNull($ban->getEndsAt());
        self::assertTrue($user->hasChatBan($now->modify('+10 years')));
    }

    public function testBanRejectsInvalidDatesAndRepeatedCharges(): void
    {
        $now = new \DateTimeImmutable();
        $user = new User('owner', 'owner@example.test', 'hash', 'en', $now);
        try {
            new ChatBan($user, 1, $now, $now);
            self::fail('A temporary ban must have a future expiration.');
        } catch (\InvalidArgumentException) {
            $ban = new ChatBan($user, 1, $now, null);
        }
        $ban->chargeTokens(10);
        $this->expectException(\LogicException::class);
        $ban->chargeTokens(10);
    }

    public function testScheduleKeepsStateAndRunsOnlyTheLastMissedCleanup(): void
    {
        $cache = new ArrayAdapter();
        $schedule = (new Schedule($cache))->getSchedule();
        self::assertSame($cache, $schedule->getState());
        self::assertTrue($schedule->shouldProcessOnlyLastMissedRun());
        $schedule->add(RecurringMessage::every('1 hour', new \stdClass()));
        $clock = new MockClock();
        $generator = new MessageGenerator($schedule, 'moderation-test', $clock);
        self::assertCount(0, iterator_to_array($generator->getMessages(), false));
        $clock->sleep(3601);
        self::assertCount(1, iterator_to_array($generator->getMessages(), false));
        unset($generator);
        $clock->sleep(10800);
        $restarted = new MessageGenerator($schedule, 'moderation-test', $clock);
        self::assertCount(1, iterator_to_array($restarted->getMessages(), false));
        self::assertCount(0, iterator_to_array($restarted->getMessages(), false));
    }
}
